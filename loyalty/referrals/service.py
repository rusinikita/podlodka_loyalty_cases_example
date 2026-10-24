from typing import Protocol

import structlog

from loyalty.clients.payout_gateway import NoPayoutMethod, Payout, PayoutRequest
from loyalty.referrals.repository import PostgresRewardRepository
from loyalty.referrals.reward import NewReward, Reward
from loyalty.referrals.status import RewardStatus

PAYOUT_CURRENCY = "RUB"
PAYOUT_PURPOSE = "referral_reward"

log = structlog.get_logger()


class PayoutClient(Protocol):
    async def pay(self, req: PayoutRequest) -> Payout: ...


class ReferralService:
    def __init__(self, repo: PostgresRewardRepository, payouts: PayoutClient) -> None:
        self._repo = repo
        self._payouts = payouts

    async def create(self, nr: NewReward) -> None:
        """Saves a reward for the inviter. The payout is done later by the
        reward-payouts worker."""
        created = await self._repo.insert(
            campaign_id=nr.campaign_id,
            user_id=nr.user_id,
            invitee_id=nr.invitee_id,
            amount=nr.amount,
            status=RewardStatus.PENDING,
        )
        if not created:
            log.info("referral reward already exists", campaign_id=nr.campaign_id, invitee_id=nr.invitee_id)
            return
        log.info(
            "referral reward created",
            campaign_id=nr.campaign_id,
            user_id=nr.user_id,
            invitee_id=nr.invitee_id,
            amount=nr.amount,
        )

    async def by_user(self, user_id: int, after_id: int, limit: int) -> list[Reward]:
        return await self._repo.by_user(user_id, after_id, limit)

    async def count_by_campaign(self, campaign_id: int) -> int:
        return await self._repo.count_by_campaign(campaign_id)

    async def pay_pending(self, batch_size: int) -> None:
        """Pays out pending rewards, walking them by id in batches of batch_size.
        A reward whose owner has no card stays pending and is tried again on
        the next run."""
        last_id = 0
        paid = no_method = failed = 0
        while batch := await self._repo.by_status_after(RewardStatus.PENDING, last_id, batch_size):
            for r in batch:
                last_id = r.id
                try:
                    await self._pay_out(r)
                except NoPayoutMethod:
                    no_method += 1
                    log.info("reward not paid", reward_id=r.id, user_id=r.user_id, reason="no_payout_method")
                except Exception as e:
                    failed += 1
                    log.warning("reward payout failed", reward_id=r.id, user_id=r.user_id, err=str(e))
                else:
                    paid += 1

        log.info("reward payouts done", paid=paid, no_payout_method=no_method, failed=failed)

    async def _pay_out(self, r: Reward) -> None:
        p = await self._payouts.pay(
            PayoutRequest(
                idempotency_key=str(r.id),
                user_id=r.user_id,
                amount=r.amount,
                currency=PAYOUT_CURRENCY,
                purpose=PAYOUT_PURPOSE,
            )
        )
        await self._repo.mark_paid(r.id, p.payout_id)
