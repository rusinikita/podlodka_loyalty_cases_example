from datetime import UTC, datetime, timedelta
from unittest.mock import AsyncMock

import pytest

from loyalty.campaigns.campaign import Campaign, Conditions, Event, RewardParams, RewardType, Trigger
from loyalty.campaigns.repository import PostgresCampaignRepository
from loyalty.campaigns.service import CampaignService
from loyalty.discounts.discount import Discount, GrantRequest
from loyalty.referrals.reward import NewReward


class FakeDiscounts:
    def __init__(self) -> None:
        self.granted: list[GrantRequest] = []

    async def grant(self, req: GrantRequest) -> Discount:
        self.granted.append(req)
        return Discount(
            id=len(self.granted),
            user_id=req.user_id,
            campaign_id=req.campaign_id,
            channel=req.channel,
            payment_id=req.payment_id,
            percent=req.percent,
            amount=req.amount,
            max_amount=req.max_amount,
            expires_at=req.expires_at,
            used_at=None,
            used_order_id=None,
            created_by=None,
            comment=None,
            created_at=datetime.now(UTC),
        )

    async def count_by_campaign(self, campaign_id: int) -> int:
        return 0

    async def count_by_user(self, campaign_id: int, user_id: int) -> int:
        return 0

    async def count_promo_used_this_month(self, user_id: int) -> int:
        return 0


class FakeReferrals:
    async def create(self, nr: NewReward) -> None:
        pass


class FakeOrders:
    def __init__(self) -> None:
        self.counts: dict[int, int] = {}
        self.error: Exception | None = None
        self.calls = 0

    async def count_orders(self, user_id: int) -> int:
        self.calls += 1
        if self.error is not None:
            raise self.error
        return self.counts.get(user_id, 0)


def new_service(*active: Campaign) -> tuple[CampaignService, FakeDiscounts, FakeOrders]:
    repo = AsyncMock(spec=PostgresCampaignRepository)
    repo.active_by_trigger.side_effect = lambda trigger, now: [c for c in active if c.trigger == trigger]
    discounts, orders = FakeDiscounts(), FakeOrders()
    return CampaignService(repo, discounts, FakeReferrals(), orders, 2), discounts, orders


def payment_campaign(campaign_id: int, slug: str, cond: Conditions) -> Campaign:
    yesterday = datetime.now(UTC) - timedelta(days=1)
    return Campaign(
        id=campaign_id,
        slug=slug,
        trigger=Trigger.PAYMENT_SUCCEEDED,
        promo_code=None,
        conditions=cond,
        reward_type=RewardType.DISCOUNT,
        reward_params=RewardParams(percent=20, ttl_days=14),
        starts_at=yesterday,
        ends_at=None,
        is_active=True,
        created_at=yesterday,
    )


def payment(user_id: int, country: str = "RU") -> Event:
    return Event(
        trigger=Trigger.PAYMENT_SUCCEEDED,
        user_id=user_id,
        country=country,
        payment_id="pay-1",
        payment_method="card",
        sku_ids=[100],
    )


FIRST_ORDER = Conditions(countries=["RU"], first_order=True)


@pytest.mark.parametrize(
    ("orders_in_history", "want_granted"),
    [
        pytest.param(1, 1, id="first order"),
        pytest.param(2, 0, id="second order"),
        pytest.param(37, 0, id="regular customer"),
        pytest.param(0, 0, id="no orders in history"),
    ],
)
async def test_handle_first_order(orders_in_history: int, want_granted: int) -> None:
    service, discounts, orders = new_service(payment_campaign(1, "first_order", FIRST_ORDER))
    orders.counts[42] = orders_in_history

    await service.handle(payment(42))

    assert len(discounts.granted) == want_granted
    if want_granted:
        g = discounts.granted[0]
        assert g.campaign_id == 1
        assert g.percent == 20
        assert g.expires_at - datetime.now(UTC) == pytest.approx(timedelta(days=14), abs=timedelta(seconds=5))
        assert g.notify


async def test_handle_first_order_other_country() -> None:
    service, discounts, orders = new_service(payment_campaign(1, "first_order", FIRST_ORDER))
    orders.counts[42] = 1

    await service.handle(payment(42, country="KZ"))

    assert discounts.granted == []
    assert orders.calls == 0


async def test_handle_without_first_order_condition() -> None:
    basket = Conditions(countries=["RU"], sku_ids=[100, 101], payment_methods=["card"])
    service, discounts, orders = new_service(payment_campaign(2, "basket", basket))

    await service.handle(payment(42))

    assert len(discounts.granted) == 1
    assert orders.calls == 0


async def test_handle_order_history_error() -> None:
    service, discounts, orders = new_service(payment_campaign(1, "first_order", Conditions(first_order=True)))
    orders.error = RuntimeError("order-history: 503 unavailable")

    with pytest.raises(RuntimeError):
        await service.handle(payment(42))
    assert discounts.granted == []
