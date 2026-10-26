from collections.abc import Callable
from datetime import UTC, datetime, timedelta
from typing import Protocol

import structlog

from loyalty.clients import antifraud
from loyalty.clients.antifraud import RewardCheck, RewardVerdict, Verdict
from loyalty.clients.payout_gateway import NoPayoutMethod, Payout, PayoutRequest
from loyalty.referrals.repository import PostgresRewardRepository
from loyalty.referrals.reward import NewReward, Reward
from loyalty.referrals.status import RewardStatus

PAYOUT_CURRENCY = "RUB"
PAYOUT_PURPOSE = "referral_reward"

# How long a reward waits for the antifraud verdict. After that it goes to
# payout anyway: fail open, honest users must not wait for antifraud longer
# than a day.
ANTIFRAUD_VERDICT_TIMEOUT = timedelta(hours=24)

log = structlog.get_logger()


class PayoutClient(Protocol):
    async def pay(self, req: PayoutRequest) -> Payout: ...


class AntifraudClient(Protocol):
    async def verdicts(self, items: list[RewardCheck]) -> list[RewardVerdict]: ...


class ReferralService:
    def __init__(
        self,
        repo: PostgresRewardRepository,
        payouts: PayoutClient,
        antifraud: AntifraudClient,
        now: Callable[[], datetime] = lambda: datetime.now(UTC),
    ) -> None:
        self._repo = repo
        self._payouts = payouts
        self._antifraud = antifraud
        self._now = now

    async def create(self, nr: NewReward) -> None:
        """Saves a reward for the inviter. The reward waits for the antifraud
        verdict (see check_antifraud); once approved, it is paid by the
        reward-payouts worker."""
        created = await self._repo.insert(
            campaign_id=nr.campaign_id,
            user_id=nr.user_id,
            invitee_id=nr.invitee_id,
            amount=nr.amount,
            status=RewardStatus.WAITING_ANTIFRAUD_RESULT,
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

    async def check_antifraud(self, batch_size: int) -> None:
        """Asks antifraud for verdicts on rewards waiting for one, walking them
        by id in batches of batch_size. Approved rewards become pending and are
        paid by reward-payouts, rejected ones are closed. A reward still
        pending at antifraud after ANTIFRAUD_VERDICT_TIMEOUT is approved."""
        batch_size = min(batch_size, antifraud.MAX_BATCH)
        waiting = RewardStatus.WAITING_ANTIFRAUD_RESULT

        last_id = 0
        approved = rejected = failed_open = still_waiting = 0
        while batch := await self._repo.by_status_after(waiting, last_id, batch_size):
            last_id = batch[-1].id
            verdicts = await self._antifraud.verdicts([_reward_check(r) for r in batch])

            now = self._now()
            for r, v in zip(batch, verdicts, strict=True):
                if v.reward_id != r.id:
                    raise ValueError(f"antifraud verdict for reward {v.reward_id}, expected {r.id}")

                waited = now - r.status_changed_at
                if v.verdict == Verdict.APPROVED:
                    await self._repo.set_status(r.id, waiting, RewardStatus.PENDING)
                    approved += 1
                elif v.verdict == Verdict.REJECTED:
                    await self._repo.set_status(r.id, waiting, RewardStatus.REJECTED)
                    rejected += 1
                    log.info("reward rejected by antifraud", reward_id=r.id, user_id=r.user_id, reason=v.reason)
                elif waited >= ANTIFRAUD_VERDICT_TIMEOUT:
                    await self._repo.set_status(r.id, waiting, RewardStatus.PENDING)
                    failed_open += 1
                    log.warning(
                        "no antifraud verdict in time, reward released to payout",
                        reward_id=r.id,
                        user_id=r.user_id,
                        waiting=str(timedelta(minutes=round(waited.total_seconds() / 60))),
                    )
                else:
                    still_waiting += 1

        log.info(
            "antifraud check done",
            approved=approved,
            rejected=rejected,
            failed_open=failed_open,
            waiting=still_waiting,
        )

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


def _reward_check(r: Reward) -> RewardCheck:
    return RewardCheck(
        reward_id=r.id,
        inviter_id=r.user_id,
        invitee_id=r.invitee_id,
        amount=r.amount,
        currency=PAYOUT_CURRENCY,
        created_at=r.created_at,
    )
