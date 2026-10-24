from datetime import datetime

import asyncpg

from loyalty.campaigns.admin import CampaignDraft, SearchFilter
from loyalty.campaigns.campaign import (
    Campaign,
    CampaignConflict,
    CampaignNotFound,
    Conditions,
    RewardParams,
    Trigger,
)

CAMPAIGN_COLUMNS = """id, slug, trigger, promo_code, conditions, reward_type, reward_params,
    starts_at, ends_at, is_active, created_by, created_at"""


class PostgresCampaignRepository:
    def __init__(self, db: asyncpg.Pool) -> None:
        self._db = db

    async def active_by_trigger(self, trigger: Trigger, now: datetime) -> list[Campaign]:
        rows = await self._db.fetch(
            f"""
            SELECT {CAMPAIGN_COLUMNS}
            FROM campaigns
            WHERE trigger = $1
              AND is_active
              AND starts_at <= $2
              AND (ends_at IS NULL OR ends_at > $2)
            ORDER BY id""",
            trigger,
            now,
        )
        return [_campaign(r) for r in rows]

    async def by_promo_code(self, code: str) -> Campaign:
        row = await self._db.fetchrow(f"SELECT {CAMPAIGN_COLUMNS} FROM campaigns WHERE promo_code = $1", code)
        return _one(row)

    async def by_id(self, campaign_id: int) -> Campaign:
        row = await self._db.fetchrow(f"SELECT {CAMPAIGN_COLUMNS} FROM campaigns WHERE id = $1", campaign_id)
        return _one(row)

    async def search(self, f: SearchFilter) -> list[Campaign]:
        rows = await self._db.fetch(
            f"""
            SELECT {CAMPAIGN_COLUMNS}
            FROM campaigns
            WHERE ($1 = '' OR slug ILIKE '%' || $1 || '%' OR promo_code ILIKE '%' || $1 || '%')
              AND ($2 = '' OR trigger = $2)
              AND ($3::boolean IS NULL OR is_active = $3)
              AND id > $4
            ORDER BY id
            LIMIT $5""",
            f.query,
            f.trigger or "",
            f.active,
            f.after_id,
            f.limit,
        )
        return [_campaign(r) for r in rows]

    async def insert(self, c: CampaignDraft) -> Campaign:
        try:
            row = await self._db.fetchrow(
                f"""
                INSERT INTO campaigns
                    (slug, trigger, promo_code, conditions, reward_type, reward_params,
                     starts_at, ends_at, is_active, created_by)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
                RETURNING {CAMPAIGN_COLUMNS}""",
                c.slug,
                c.trigger,
                c.promo_code,
                c.conditions.model_dump(exclude_none=True),
                c.reward_type,
                c.reward_params.model_dump(exclude_none=True),
                c.starts_at,
                c.ends_at,
                c.is_active,
                c.created_by,
            )
        except asyncpg.UniqueViolationError as e:
            raise CampaignConflict() from e
        return _one(row)

    async def update(self, campaign_id: int, c: CampaignDraft) -> Campaign:
        try:
            row = await self._db.fetchrow(
                f"""
                UPDATE campaigns
                SET slug = $2, trigger = $3, promo_code = $4, conditions = $5, reward_type = $6,
                    reward_params = $7, starts_at = $8, ends_at = $9, is_active = $10
                WHERE id = $1
                RETURNING {CAMPAIGN_COLUMNS}""",
                campaign_id,
                c.slug,
                c.trigger,
                c.promo_code,
                c.conditions.model_dump(exclude_none=True),
                c.reward_type,
                c.reward_params.model_dump(exclude_none=True),
                c.starts_at,
                c.ends_at,
                c.is_active,
            )
        except asyncpg.UniqueViolationError as e:
            raise CampaignConflict() from e
        return _one(row)


def _campaign(row: asyncpg.Record) -> Campaign:
    return Campaign(
        id=row["id"],
        slug=row["slug"],
        trigger=row["trigger"],
        promo_code=row["promo_code"],
        conditions=Conditions.model_validate(row["conditions"]),
        reward_type=row["reward_type"],
        reward_params=RewardParams.model_validate(row["reward_params"]),
        starts_at=row["starts_at"],
        ends_at=row["ends_at"],
        is_active=row["is_active"],
        created_by=row["created_by"],
        created_at=row["created_at"],
    )


def _one(row: asyncpg.Record | None) -> Campaign:
    if row is None:
        raise CampaignNotFound()
    return _campaign(row)
