"""
clients.py — 调用 llama.cpp server 的 embedding 与 rerank 接口
- embedding: OpenAI 兼容  POST {EMBEDDER_BASE}/v1/embeddings
- rerank:    llama.cpp     POST {RERANKER_BASE}/v1/rerank
两者 API key 可空(本地服务)。所有地址/模型走环境变量。
"""
import os
from typing import List

import httpx

EMBEDDER_BASE = os.getenv("EMBEDDER_BASE", "").rstrip("/")
EMBEDDER_MODEL = os.getenv("EMBEDDER_MODEL", "")        # llama.cpp 可空
EMBEDDER_KEY = os.getenv("EMBEDDER_KEY", "")            # 可空

RERANKER_BASE = os.getenv("RERANKER_BASE", "").rstrip("/")
RERANKER_MODEL = os.getenv("RERANKER_MODEL", "")
RERANKER_KEY = os.getenv("RERANKER_KEY", "")

EMBED_PATH = os.getenv("EMBED_PATH", "/v1/embeddings")
RERANK_PATH = os.getenv("RERANK_PATH", "/v1/rerank")
TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "60"))


def _headers(key: str) -> dict:
    h = {"Content-Type": "application/json"}
    if key:
        h["Authorization"] = f"Bearer {key}"
    return h


def embed(texts: List[str]) -> List[List[float]]:
    """批量取 embedding。返回与输入等长的向量列表。"""
    if not texts:
        return []
    if not EMBEDDER_BASE:
        raise RuntimeError("EMBEDDER_BASE 未配置")
    payload = {"input": texts}
    if EMBEDDER_MODEL:
        payload["model"] = EMBEDDER_MODEL
    with httpx.Client(timeout=TIMEOUT) as client:
        r = client.post(
            f"{EMBEDDER_BASE}{EMBED_PATH}",
            headers=_headers(EMBEDDER_KEY),
            json=payload,
        )
        r.raise_for_status()
        data = r.json()
    # OpenAI 兼容:{"data":[{"embedding":[...],"index":0}, ...]}
    items = sorted(data["data"], key=lambda x: x.get("index", 0))
    return [it["embedding"] for it in items]


def embed_one(text: str) -> List[float]:
    return embed([text])[0]


def rerank(query: str, documents: List[str], top_n: int) -> List[dict]:
    """
    调 llama.cpp /v1/rerank。
    返回 [{"index": i, "score": s}] 按相关度降序(index 对应 documents 下标)。
    若 reranker 未配置或失败,调用方应回退到向量分数。
    """
    if not RERANKER_BASE:
        raise RuntimeError("RERANKER_BASE 未配置")
    if not documents:
        return []
    payload = {
        "query": query,
        "documents": documents,
        "top_n": min(top_n, len(documents)),
    }
    if RERANKER_MODEL:
        payload["model"] = RERANKER_MODEL
    with httpx.Client(timeout=TIMEOUT) as client:
        r = client.post(
            f"{RERANKER_BASE}{RERANK_PATH}",
            headers=_headers(RERANKER_KEY),
            json=payload,
        )
        r.raise_for_status()
        data = r.json()
    # llama.cpp / Cohere 兼容:{"results":[{"index":2,"relevance_score":0.9}, ...]}
    out = []
    for it in data.get("results", []):
        out.append({
            "index": it["index"],
            "score": it.get("relevance_score", it.get("score", 0.0)),
        })
    out.sort(key=lambda x: x["score"], reverse=True)
    return out
