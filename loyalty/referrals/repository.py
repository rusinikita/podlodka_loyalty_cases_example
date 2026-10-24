import asyncpg

from loyalty.referrals.reward import Reward
from loyalty.referrals.status import RewardStatus

REWARD_COLUMNS = """id, campaign_id, user_id, invitee_id, amount, status, status_changed_at,
    payout_id::text, created_at"""


class PostgresRewardRepository:
    def __init__(self, db: asyncpg.Pool) -> None:
        self._db = db

    async def insert(self, campaign_id: int, user_id: int, invitee_id: int, amount: int, status: RewardStatus) -> bool:
        """Saves the reward unless the invitee already has one in the campaign;
        returns False in that case."""
        result = await self._db.execute(
            """
            INSERT INTO referral_rewards (campaign_id, user_id, invitee_id, amount, status)
            VALUES ($1, $2, $3, $4, $5)
            ON CONFLICT (campaign_id, invitee_id) DO NOTHING""",
            campaign_id,
            user_id,
            invitee_id,
            amount,
            status,
        )
        return result == "INSERT 0 1"

    async def by_user(self, user_id: int, after_id: int, limit: int) -> list[Reward]:
        rows = await self._db.fetch(
            f"""
            SELECT {REWARD_COLUMNS}
            FROM referral_rewards
            WHERE user_id = $1 AND ($2::bigint = 0 OR id < $2)
            ORDER BY id DESC
            LIMIT $3""",
            user_id,
            after_id,
            limit,
        )
        return [_reward(r) for r in rows]

    async def count_by_campaign(self, campaign_id: int) -> int:
        n: int = await self._db.fetchval("SELECT count(*) FROM referral_rewards WHERE campaign_id = $1", campaign_id)
        return n

    async def by_status_after(self, status: RewardStatus, after_id: int, limit: int) -> list[Reward]:
        rows = await self._db.fetch(
            f"""
            SELECT {REWARD_COLUMNS}
            FROM referral_rewards
            WHERE status = $1 AND id > $2
            ORDER BY id
            LIMIT $3""",
            status,
            after_id,
            limit,
        )
        return [_reward(r) for r in rows]

    async def mark_paid(self, reward_id: int, payout_id: str) -> None:
        await self._db.execute(
            """
            UPDATE referral_rewards
            SET status = $2, status_changed_at = now(), payout_id = $3::uuid
            WHERE id = $1 AND status = $4""",
            reward_id,
            RewardStatus.PAID,
            payout_id,
            RewardStatus.PENDING,
        )


def _reward(row: asyncpg.Record) -> Reward:
    return Reward(
        id=row["id"],
        campaign_id=row["campaign_id"],
        user_id=row["user_id"],
        invitee_id=row["invitee_id"],
        amount=row["amount"],
        status=row["status"],
        status_changed_at=row["status_changed_at"],
        payout_id=row["payout_id"],
        created_at=row["created_at"],
    )
