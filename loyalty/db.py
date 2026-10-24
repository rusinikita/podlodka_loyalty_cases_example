"""Connection pool to the loyalty database."""

import json

import asyncpg


async def _init(conn: asyncpg.Connection) -> None:
    await conn.set_type_codec("jsonb", encoder=json.dumps, decoder=json.loads, schema="pg_catalog")


async def connect(dsn: str, pool_size: int) -> asyncpg.Pool:
    size = pool_size if pool_size > 0 else 10
    return await asyncpg.create_pool(dsn, min_size=1, max_size=size, init=_init)
