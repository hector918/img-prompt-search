"""
db.py — PostgreSQL + pgvector 数据层
- 启动时只“检查”扩展与表是否就绪;缺失则抛错并打印需要执行的 SQL(由 DBA 用超级用户处理)
- 提供:upsert(单条/批量)、delete、向量召回(带 tags +/- 过滤 + 时间过滤)
"""
import os
import json
from typing import List, Optional, Tuple

import psycopg
from psycopg.rows import dict_row

EMBED_DIM = int(os.getenv("EMBED_DIM", "1024"))

# 期望的库结构(仅检查,不自动创建;缺失时把这段打印给用户)
REQUIRED_SQL = f"""
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS images (
    id          BIGINT PRIMARY KEY,
    caption     TEXT NOT NULL DEFAULT '',
    prompt      TEXT NOT NULL DEFAULT '',
    tags        TEXT[] NOT NULL DEFAULT '{{}}',
    embedding   vector({EMBED_DIM}),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS images_embedding_idx
    ON images USING hnsw (embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS images_tags_idx
    ON images USING gin (tags);
CREATE INDEX IF NOT EXISTS images_created_idx
    ON images (created_at);
"""


def _vec(v) -> str:
    """把向量列表格式化为 pgvector 字面量 '[1,2,3]',配合 SQL 里的 %s::vector 使用。"""
    return "[" + ",".join(repr(float(x)) for x in v) + "]"


def _connect() -> psycopg.Connection:
    dsn = os.getenv("PG_DSN")
    if not dsn:
        raise RuntimeError("PG_DSN 未设置")
    conn = psycopg.connect(dsn, autocommit=True, row_factory=dict_row)
    return conn


def check_ready() -> None:
    """启动时调用:检查 vector 扩展 + images 表 + 维度是否匹配。缺失则抛错并附上 SQL。"""
    try:
        conn = _connect()
    except Exception as e:
        raise RuntimeError(f"无法连接 PG(检查 PG_DSN):{e}")

    with conn:
        # 1. vector 扩展
        ext = conn.execute(
            "SELECT 1 FROM pg_extension WHERE extname = 'vector'"
        ).fetchone()
        if not ext:
            raise RuntimeError(
                "缺少 pgvector 扩展。请用超级用户执行以下 SQL 后重启本服务:\n\n"
                + REQUIRED_SQL
            )

        # 2. images 表
        tbl = conn.execute(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'images'"
        ).fetchone()
        if not tbl:
            raise RuntimeError(
                "缺少 images 表。请执行以下 SQL 后重启本服务:\n\n" + REQUIRED_SQL
            )

        # 3. 维度校验(embedding 列的 typmod 即维度)
        dim = conn.execute(
            """
            SELECT a.atttypmod AS dim
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            WHERE c.relname = 'images' AND a.attname = 'embedding'
            """
        ).fetchone()
        if dim and dim["dim"] not in (-1, EMBED_DIM):
            raise RuntimeError(
                f"images.embedding 维度为 {dim['dim']},但服务配置 EMBED_DIM={EMBED_DIM}。"
                f"请使两者一致。"
            )
    conn.close()


def upsert(items: List[dict]) -> int:
    """
    items: [{id, caption, prompt, tags:[...], embedding:[...]}]
    存在则更新,不存在则插入。返回写入条数。
    """
    if not items:
        return 0
    conn = _connect()
    n = 0
    with conn:
        with conn.cursor() as cur:
            for it in items:
                cur.execute(
                    """
                    INSERT INTO images (id, caption, prompt, tags, embedding, updated_at)
                    VALUES (%s, %s, %s, %s, %s::vector, now())
                    ON CONFLICT (id) DO UPDATE SET
                        caption    = EXCLUDED.caption,
                        prompt     = EXCLUDED.prompt,
                        tags       = EXCLUDED.tags,
                        embedding  = EXCLUDED.embedding,
                        updated_at = now()
                    """,
                    (
                        int(it["id"]),
                        it.get("caption", "") or "",
                        it.get("prompt", "") or "",
                        list(it.get("tags", []) or []),
                        _vec(it["embedding"]),
                    ),
                )
                n += 1
    conn.close()
    return n


def delete(ids: List[int]) -> int:
    if not ids:
        return 0
    conn = _connect()
    with conn:
        cur = conn.execute(
            "DELETE FROM images WHERE id = ANY(%s)", (list(map(int, ids)),)
        )
        n = cur.rowcount
    conn.close()
    return n


def _parse_tags(tags: Optional[List[str]]) -> Tuple[List[str], List[str]]:
    """
    把 ["+风景","-人物","夕阳"] 拆成 (必须包含, 必须排除)。
    +前缀或无前缀 => 必须包含(all 语义);-前缀 => 必须排除。
    符号不会用于 tag 名,故可安全解析。
    """
    include, exclude = [], []
    for t in tags or []:
        t = t.strip()
        if not t:
            continue
        if t.startswith("-"):
            exclude.append(t[1:])
        elif t.startswith("+"):
            include.append(t[1:])
        else:
            include.append(t)
    return include, exclude


def recall(
    query_vec: List[float],
    candidates: int,
    tags: Optional[List[str]] = None,
    after: Optional[str] = None,
    before: Optional[str] = None,
) -> List[dict]:
    """
    向量召回 + tags(all 含 / 排除)+ 时间过滤。
    返回候选(给 reranker):[{id, caption, prompt, tags, score, created_at}]
    score = 1 - cosine_distance(越大越相似)
    """
    include, exclude = _parse_tags(tags)
    where = []
    params: list = []

    if include:
        where.append("tags @> %s")          # 数组包含(全含)
        params.append(include)
    if exclude:
        where.append("NOT (tags && %s)")    # 与排除集无交集
        params.append(exclude)
    if after:
        where.append("created_at >= %s")
        params.append(after)
    if before:
        where.append("created_at <= %s")
        params.append(before)

    where_sql = ("WHERE " + " AND ".join(where)) if where else ""

    sql = f"""
        SELECT id, caption, prompt, tags, created_at,
               1 - (embedding <=> %s::vector) AS score
        FROM images
        {where_sql}
        ORDER BY embedding <=> %s::vector
        LIMIT %s
    """
    # 注意参数顺序:select 里的 <=> 一个,where 若干,order 里 <=> 一个,最后 limit
    qlit = _vec(query_vec)
    full_params = [qlit] + params + [qlit, candidates]

    conn = _connect()
    with conn:
        rows = conn.execute(sql, full_params).fetchall()
    conn.close()
    return rows


def get_by_ids(ids: List[int]) -> List[dict]:
    if not ids:
        return []
    conn = _connect()
    with conn:
        rows = conn.execute(
            "SELECT id, caption, prompt, tags, created_at FROM images WHERE id = ANY(%s)",
            (list(map(int, ids)),),
        ).fetchall()
    conn.close()
    return rows
