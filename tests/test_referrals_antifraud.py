from datetime import UTC, datetime, timedelta

import pytest

from loyalty.clients.antifraud import RewardCheck, RewardVerdict, Verdict
from loyalty.clients.payout_gateway import Payout, PayoutRequest
from loyalty.referrals.repository import PostgresRewardRepository
from loyalty.referrals.reward import NewReward, Reward
from loyalty.referrals.service import ReferralService
from loyalty.referrals.status import RewardStatus

TEST_NOW = datetime(2026, 10, 20, 12, 0, tzinfo=UTC)


class FakeRepo(PostgresRewardRepository):
    def __init__(self, *rewards: Reward) -> None:
        self.rewards = {r.id: r for r in rewards}
        self.inserted: list[RewardStatus] = []

    async def insert(self, campaign_id: int, user_id: int, invitee_id: int, amount: int, status: RewardStatus) -> bool:
        self.inserted.append(status)
        return True

    async def by_status_after(self, status: RewardStatus, after_id: int, limit: int) -> list[Reward]:
        found = sorted((r for r in self.rewards.values() if r.status == status and r.id > after_id), key=lambda r: r.id)
        return found[:limit]

    async def set_status(self, reward_id: int, from_: RewardStatus, to: RewardStatus) -> None:
        r = self.rewards.get(reward_id)
        if r is not None and r.status == from_:
            self.rewards[reward_id] = r.model_copy(update={"status": to, "status_changed_at": TEST_NOW})


class FakeAntifraud:
    def __init__(self) -> None:
        self.verdicts_by_id: dict[int, Verdict] = {}
        self.calls: list[list[int]] = []

    async def verdicts(self, items: list[RewardCheck]) -> list[RewardVerdict]:
        self.calls.append([it.reward_id for it in items])
        return [
            RewardVerdict(reward_id=it.reward_id, verdict=self.verdicts_by_id.get(it.reward_id, Verdict.PENDING))
            for it in items
        ]


class NoPayouts:
    async def pay(self, req: PayoutRequest) -> Payout:
        raise AssertionError("no payouts expected")


def new_service(*rewards: Reward) -> tuple[ReferralService, FakeRepo, FakeAntifraud]:
    repo, af = FakeRepo(*rewards), FakeAntifraud()
    return ReferralService(repo, NoPayouts(), af, now=lambda: TEST_NOW), repo, af


def waiting(reward_id: int, since: timedelta) -> Reward:
    return Reward(
        id=reward_id,
        campaign_id=17,
        user_id=1000 + reward_id,
        invitee_id=2000 + reward_id,
        amount=500,
        status=RewardStatus.WAITING_ANTIFRAUD_RESULT,
        status_changed_at=TEST_NOW - since,
        payout_id=None,
        created_at=TEST_NOW - since,
    )


@pytest.mark.parametrize(
    ("reward", "verdict", "want"),
    [
        pytest.param(waiting(1, timedelta(hours=1)), Verdict.APPROVED, RewardStatus.PENDING, id="approved"),
        pytest.param(waiting(2, timedelta(hours=1)), Verdict.REJECTED, RewardStatus.REJECTED, id="rejected"),
        pytest.param(
            waiting(3, timedelta(hours=6)), Verdict.PENDING, RewardStatus.WAITING_ANTIFRAUD_RESULT, id="pending, fresh"
        ),
        pytest.param(
            waiting(4, timedelta(hours=23, minutes=59)),
            Verdict.PENDING,
            RewardStatus.WAITING_ANTIFRAUD_RESULT,
            id="pending, almost a day",
        ),
        pytest.param(waiting(5, timedelta(hours=24)), Verdict.PENDING, RewardStatus.PENDING, id="pending, a day"),
        pytest.param(waiting(6, timedelta(hours=48)), Verdict.PENDING, RewardStatus.PENDING, id="pending, two days"),
        pytest.param(
            waiting(7, timedelta(hours=30)), Verdict.REJECTED, RewardStatus.REJECTED, id="rejected after a day"
        ),
    ],
)
async def test_check_antifraud(reward: Reward, verdict: Verdict, want: RewardStatus) -> None:
    service, repo, af = new_service(reward)
    af.verdicts_by_id[reward.id] = verdict

    await service.check_antifraud(500)

    assert repo.rewards[reward.id].status == want


async def test_check_antifraud_batches() -> None:
    rewards = [waiting(i, timedelta(hours=1)) for i in range(1, 8)]
    paid = waiting(8, timedelta(hours=1)).model_copy(update={"status": RewardStatus.PAID})
    service, repo, af = new_service(*rewards, paid)

    await service.check_antifraud(3)

    assert af.calls == [[1, 2, 3], [4, 5, 6], [7]]
    assert repo.rewards[8].status == RewardStatus.PAID


async def test_create_waits_for_antifraud() -> None:
    service, repo, _ = new_service()

    await service.create(NewReward(campaign_id=17, user_id=1, invitee_id=2, amount=500))

    assert repo.inserted == [RewardStatus.WAITING_ANTIFRAUD_RESULT]
