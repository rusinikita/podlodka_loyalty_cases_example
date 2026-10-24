from datetime import datetime

import asyncpg

from loyalty.discounts.discount import Discount, OrderConditions, Redeemable

DISCOUNT_COLUMNS = """d.id, d.user_id, d.campaign_id, d.channel, d.payment_id, d.percent, d.amount,
    d.max_amount, d.expires_at, d.used_at, d.used_order_id, d.created_by, d.comment, d.created_at"""


class PostgresDiscountRepository:
    def __init__(self, db: asyncpg.Pool) -> None:
        self._db = db

    async def insert(
        self,
        *,
        user_id: int,
        expires_at: datetime,
        campaign_id: int | None = None,
        channel: str | None = None,
        payment_id: str | None = None,
        percent: int | None = None,
        amount: int | None = None,
        max_amount: int | None = None,
        created_by: str | None = None,
        comment: str | None = None,
    ) -> Discount:
        row = await self._db.fetchrow(
            f"""
            INSERT INTO user_discounts AS d
                (user_id, campaign_id, channel, payment_id, percent, amount, max_amount,
                 expires_at, created_by, comment)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
            RETURNING {DISCOUNT_COLUMNS}""",
            user_id,
            campaign_id,
            channel,
            payment_id,
            percent,
            amount,
            max_amount,
            expires_at,
            created_by,
            comment,
        )
        assert row is not None
        return _discount(row)

    async def active(self, user_id: int, now: datetime) -> list[Discount]:
        rows = await self._db.fetch(
            f"""
            SELECT {DISCOUNT_COLUMNS}
            FROM user_discounts d
            WHERE d.user_id = $1 AND d.used_at IS NULL AND d.expires_at > $2
            ORDER BY d.expires_at""",
            user_id,
            now,
        )
        return [_discount(r) for r in rows]

    async def by_user(self, user_id: int, after_id: int, limit: int) -> list[Discount]:
        rows = await self._db.fetch(
            f"""
            SELECT {DISCOUNT_COLUMNS}
            FROM user_discounts d
            WHERE d.user_id = $1 AND ($2::bigint = 0 OR d.id < $2)
            ORDER BY d.id DESC
            LIMIT $3""",
            user_id,
            after_id,
            limit,
        )
        return [_discount(r) for r in rows]

    async def redeemable(self, user_id: int, at: datetime) -> list[Redeemable]:
        rows = await self._db.fetch(
            f"""
            SELECT {DISCOUNT_COLUMNS}, coalesce(c.conditions, '{{}}') AS conditions
            FROM user_discounts d
            LEFT JOIN campaigns c ON c.id = d.campaign_id
            WHERE d.user_id = $1 AND d.used_at IS NULL AND d.expires_at > $2""",
            user_id,
            at,
        )
        return [Redeemable(_discount(r), OrderConditions.model_validate(r["conditions"])) for r in rows]

    async def mark_used(self, discount_id: int, user_id: int, order_id: int) -> bool:
        status = await self._db.execute(
            """
            UPDATE user_discounts
            SET used_at = now(), used_order_id = $3
            WHERE id = $1 AND user_id = $2 AND used_at IS NULL AND expires_at > now()""",
            discount_id,
            user_id,
            order_id,
        )
        return status == "UPDATE 1"

    async def count_by_campaign(self, campaign_id: int) -> int:
        n: int = await self._db.fetchval("SELECT count(*) FROM user_discounts WHERE campaign_id = $1", campaign_id)
        return n

    async def count_by_user(self, campaign_id: int, user_id: int) -> int:
        n: int = await self._db.fetchval(
            "SELECT count(*) FROM user_discounts WHERE campaign_id = $1 AND user_id = $2",
            campaign_id,
            user_id,
        )
        return n

    async def count_promo_used_this_month(self, user_id: int) -> int:
        n: int = await self._db.fetchval(
            """
            SELECT count(*)
            FROM user_discounts d
            JOIN campaigns c ON c.id = d.campaign_id
            WHERE d.user_id = $1
              AND c.trigger = 'promo_code'
              AND d.used_at >= date_trunc('month', now())""",
            user_id,
        )
        return n


def _discount(row: asyncpg.Record) -> Discount:
    return Discount(
        id=row["id"],
        user_id=row["user_id"],
        campaign_id=row["campaign_id"],
        channel=row["channel"],
        payment_id=row["payment_id"],
        percent=row["percent"],
        amount=row["amount"],
        max_amount=row["max_amount"],
        expires_at=row["expires_at"],
        used_at=row["used_at"],
        used_order_id=row["used_order_id"],
        created_by=row["created_by"],
        comment=row["comment"],
        created_at=row["created_at"],
    )
