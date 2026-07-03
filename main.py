"""
main.py — 搜索服务(块 A)
端点(供 WordPress 插件经 tunnel 调用):
  GET  /health
  POST /index           单条索引   {id, caption, prompt, tags}
  POST /index/batch     批量索引   {items:[{id, caption, prompt, tags}, ...]}
  POST /search          搜索       {query, limit, tags:["+a","-b"], after, before, rerank}
  POST /delete          删除       {ids:[...]}

鉴权:默认 API_KEY 为空=放行;设了则除 /health 外需  Authorization: Bearer <API_KEY>
embedding 文本 = caption + "\n" + prompt(与 rerank 重排文本一致)
"""
import os
import time
import threading
from collections import OrderedDict
from typing import List, Optional

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

import db
import clients

API_KEY = os.getenv("API_KEY", "")
RERANK_CANDIDATES = int(os.getenv("RERANK_CANDIDATES", "100"))

app = FastAPI(title="WordPress Image Prompt Search", version="1.0")

# ---------- 搜索结果缓存(块 A:保护最脆的 embed+rerank/GPU)----------
# 按归一化查询参数缓存 /search 的返回;命中直接回,跳过 embed+召回+rerank。
# 失效:TTL 兜底 + 任何索引写入(index/batch/delete)清空(内容一变即新鲜)。
# 进程内内存缓存(多 worker 则各自一份,不共享;要跨进程再上 Redis)。sync 端点跑在
# uvicorn 线程池里 → 用锁保护。
SEARCH_CACHE_TTL = int(os.getenv("SEARCH_CACHE_TTL", "120"))   # 秒
SEARCH_CACHE_MAX = int(os.getenv("SEARCH_CACHE_MAX", "512"))   # 条数上限(LRU 淘汰)
_cache = OrderedDict()          # key -> (expires_at, value)
_cache_lock = threading.Lock()
_cache_stats = {"hits": 0, "misses": 0}


def _cache_key(body: "SearchReq") -> str:
    q = " ".join((body.query or "").strip().lower().split())
    tags = ",".join(sorted(body.tags)) if body.tags else ""
    return "|".join([q, str(int(body.limit)), tags,
                     body.after or "", body.before or "", "1" if body.rerank else "0"])


def _cache_get(key):
    now = time.time()
    with _cache_lock:
        item = _cache.get(key)
        if item is None:
            _cache_stats["misses"] += 1
            return None
        expires, val = item
        if expires < now:
            _cache.pop(key, None)
            _cache_stats["misses"] += 1
            return None
        _cache.move_to_end(key)      # LRU:命中移到末尾
        _cache_stats["hits"] += 1
        return val


def _cache_set(key, val):
    with _cache_lock:
        _cache[key] = (time.time() + SEARCH_CACHE_TTL, val)
        _cache.move_to_end(key)
        while len(_cache) > SEARCH_CACHE_MAX:
            _cache.popitem(last=False)   # 淘汰最旧


def _cache_clear():
    with _cache_lock:
        _cache.clear()


def _auth(authorization: Optional[str]):
    # 默认空 = 放行(内网起步);设了 API_KEY 则全局强制(除 /health)
    if not API_KEY:
        return
    expected = f"Bearer {API_KEY}"
    if authorization != expected:
        raise HTTPException(status_code=401, detail="invalid api key")


def _doc_text(caption: str, prompt: str) -> str:
    return ((caption or "").strip() + "\n" + (prompt or "").strip()).strip()


# ---------- 数据模型 ----------
class IndexItem(BaseModel):
    id: int
    caption: str = ""
    prompt: str = ""
    tags: List[str] = Field(default_factory=list)


class IndexBatch(BaseModel):
    items: List[IndexItem]


class SearchReq(BaseModel):
    query: str
    limit: int = 30
    tags: Optional[List[str]] = None          # ["+风景","-人物"];+/无前缀=含, -=排除
    after: Optional[str] = None               # ISO 时间下限
    before: Optional[str] = None              # ISO 时间上限
    rerank: bool = True                       # 是否启用 reranker


class DeleteReq(BaseModel):
    ids: List[int]


# ---------- 生命周期 ----------
@app.on_event("startup")
def _startup():
    # 只检查库是否就绪;缺失则抛错(含修复 SQL),服务不启动
    db.check_ready()


# ---------- 端点 ----------
@app.get("/health")
def health():
    with _cache_lock:
        cache = {"size": len(_cache), "hits": _cache_stats["hits"],
                 "misses": _cache_stats["misses"], "ttl": SEARCH_CACHE_TTL}
    return {"ok": True, "cache": cache}


@app.post("/index")
def index_one(item: IndexItem, authorization: Optional[str] = Header(None)):
    _auth(authorization)
    vec = clients.embed_one(_doc_text(item.caption, item.prompt))
    n = db.upsert([{
        "id": item.id, "caption": item.caption, "prompt": item.prompt,
        "tags": item.tags, "embedding": vec,
    }])
    _cache_clear()   # 内容变了 → 清搜索缓存
    return {"ok": True, "indexed": n}


@app.post("/index/batch")
def index_batch(body: IndexBatch, authorization: Optional[str] = Header(None)):
    _auth(authorization)
    if not body.items:
        return {"ok": True, "indexed": 0}
    texts = [_doc_text(it.caption, it.prompt) for it in body.items]
    vecs = clients.embed(texts)              # 一次批量 embedding
    rows = []
    for it, v in zip(body.items, vecs):
        rows.append({
            "id": it.id, "caption": it.caption, "prompt": it.prompt,
            "tags": it.tags, "embedding": v,
        })
    n = db.upsert(rows)
    _cache_clear()   # 内容变了 → 清搜索缓存
    return {"ok": True, "indexed": n}


@app.post("/delete")
def delete(body: DeleteReq, authorization: Optional[str] = Header(None)):
    _auth(authorization)
    n = db.delete(body.ids)
    _cache_clear()   # 内容变了 → 清搜索缓存
    return {"ok": True, "deleted": n}


@app.post("/search")
def search(body: SearchReq, authorization: Optional[str] = Header(None)):
    _auth(authorization)
    if not body.query.strip():
        raise HTTPException(status_code=400, detail="query is empty")

    # 0. 缓存命中直接回(跳过 embed+召回+rerank,保护 GPU)
    ckey = _cache_key(body)
    hit = _cache_get(ckey)
    if hit is not None:
        return hit

    # 1. query -> 向量
    qvec = clients.embed_one(body.query.strip())

    # 2. 向量召回(宽候选)+ tags/时间过滤
    cand_n = max(body.limit, RERANK_CANDIDATES) if body.rerank else body.limit
    rows = db.recall(
        query_vec=qvec,
        candidates=cand_n,
        tags=body.tags,
        after=body.after,
        before=body.before,
    )
    if not rows:
        result = {"ids": [], "results": []}
        _cache_set(ckey, result)     # 空结果也缓存,别每次重跑
        return result

    # 3. 可选 rerank
    if body.rerank and len(rows) > 1:
        docs = [_doc_text(r["caption"], r["prompt"]) for r in rows]
        try:
            ranked = clients.rerank(body.query.strip(), docs, top_n=body.limit)
            ordered = []
            for rk in ranked:
                r = rows[rk["index"]]
                ordered.append({
                    "id": r["id"],
                    "score": rk["score"],
                    "vector_score": r["score"],
                })
            rows_out = ordered[: body.limit]
        except Exception as e:
            # reranker 挂了 -> 回退向量分数排序
            rows_out = [{"id": r["id"], "score": r["score"], "vector_score": r["score"]}
                        for r in rows[: body.limit]]
    else:
        rows_out = [{"id": r["id"], "score": r["score"], "vector_score": r["score"]}
                    for r in rows[: body.limit]]

    result = {"ids": [x["id"] for x in rows_out], "results": rows_out}
    _cache_set(ckey, result)
    return result
