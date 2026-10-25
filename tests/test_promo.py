from datetime import UTC, datetime, timedelta

import pytest

from loyalty.campaigns.campaign import (
    Campaign,
    CampaignNotFound,
    Conditions,
    Event,
    RewardParams,
    RewardType,
    Trigger,
)
from loyalty.discounts.discount import Discount
from loyalty.promo.service import ApplyRequest, Channel, PromoNotFound, PromoService, normalize_code


@pytest.mark.parametrize(
    ("raw", "want"),
    [
        pytest.param("WELCOME300", "WELCOME300", id="as stored"),
        pytest.param("welcome300", "WELCOME300", id="lower case"),
        pytest.param("WeLcOmE300", "WELCOME300", id="mixed case"),
        pytest.param("  WELCOME300 \n", "WELCOME300", id="surrounding spaces"),
        pytest.param("\tWELCOME300", "WELCOME300", id="tab"),
        pytest.param("WELC\u200bOME300", "WELCOME300", id="zero-width space inside"),
        pytest.param("\u200dWELCOME\u200c300", "WELCOME300", id="zero-width joiner and non-joiner"),
        pytest.param("WELCOME\u2060300", "WELCOME300", id="word joiner"),
        pytest.param("\ufeffWELCOME300", "WELCOME300", id="byte order mark"),
        pytest.param("WEL\u00adCOME300", "WELCOME300", id="soft hyphen"),
        pytest.param("WELCOME\u00a0300", "WELCOME300", id="no-break space inside"),
        pytest.param("WELCOME300\u202f", "WELCOME300", id="narrow no-break space at the end"),
        pytest.param("ＷＥＬＣＯＭＥ３００", "WELCOME300", id="full-width letters and digits"),
        pytest.param("AUTUMN１５", "AUTUMN15", id="full-width digits only"),
        pytest.param("ａｕｔｕｍｎ15", "AUTUMN15", id="full-width lower case"),
        pytest.param("pulse-93kd", "PULSE-93KD", id="code with a dash"),
        pytest.param(" \u200bautumn１５\u00a0", "AUTUMN15", id="pasted from a message"),
        pytest.param("", "", id="empty"),
        pytest.param("\u200b\u00ad\ufeff", "", id="only invisible characters"),
    ],
)
def test_normalize_code(raw: str, want: str) -> None:
    assert normalize_code(raw) == want


class FakeCampaigns:
    def __init__(self, *campaigns: Campaign) -> None:
        self.by_code = {c.promo_code: c for c in campaigns}
        self.applied: list[str] = []

    async def by_promo_code(self, code: str) -> Campaign:
        if code not in self.by_code:
            raise CampaignNotFound(code)
        return self.by_code[code]

    async def apply_promo_code(self, c: Campaign, ev: Event) -> Discount:
        assert c.promo_code is not None
        self.applied.append(c.promo_code)
        now = datetime.now(UTC)
        return Discount(
            id=len(self.applied),
            user_id=ev.user_id,
            campaign_id=c.id,
            channel=ev.channel,
            payment_id=None,
            percent=None,
            amount=300,
            max_amount=None,
            expires_at=now + timedelta(days=14),
            used_at=None,
            used_order_id=None,
            created_by=None,
            comment=None,
            created_at=now,
        )


def promo_campaign(code: str) -> Campaign:
    now = datetime.now(UTC)
    return Campaign(
        id=1,
        slug="welcome_300",
        trigger=Trigger.PROMO_CODE,
        promo_code=code,
        conditions=Conditions(),
        reward_type=RewardType.DISCOUNT,
        reward_params=RewardParams(amount=300),
        starts_at=now - timedelta(days=1),
        ends_at=None,
        is_active=True,
        created_at=now - timedelta(days=1),
    )


def request(code: str) -> ApplyRequest:
    return ApplyRequest(user_id=7, country="RU", city_id=1, segment="", code=code, channel=Channel.MANUAL)


async def test_apply_normalized_input() -> None:
    campaigns = FakeCampaigns(promo_campaign("WELCOME300"))
    service = PromoService(campaigns)

    inputs = ["WELCOME300", "welcome300", "WELC\u200bOME300", "ＷＥＬＣＯＭＥ３００", "WELCOME\u00a0300 "]
    for raw in inputs:
        await service.apply(request(raw))
    assert len(campaigns.applied) == len(inputs)

    with pytest.raises(PromoNotFound):
        await service.apply(request("WELCOME30"))
