from collections.abc import Callable
from typing import Any

import httpx
import pytest

from mock_server import MockServer
from support import REGISTRATIONS_TOPIC, create_campaign, event_id, eventually, iso, new_user_id, now, user_headers

Publish = Callable[[str, int, dict[str, Any]], None]


def create_referral_campaign(api: httpx.Client, slug: str, country: str, amount: int) -> dict[str, Any]:
    return create_campaign(
        api,
        slug=slug,
        trigger="user_registered",
        conditions={"countries": [country], "invited": True},
        reward_type="referral_reward",
        reward_params={"amount": amount, "recipient": "inviter"},
    )


def register(publish: Publish, user_id: int, country: str, invited_by: int) -> None:
    publish(
        REGISTRATIONS_TOPIC,
        user_id,
        {
            "event_id": event_id(),
            "event_type": "user.registered",
            "occurred_at": iso(now()),
            "user_id": user_id,
            "country": country,
            "city_id": 1,
            "invited_by": invited_by,
        },
    )


def rewards(api: httpx.Client, user_id: int, country: str) -> list[dict[str, Any]]:
    resp = api.get("/v1/referrals/rewards", headers=user_headers(user_id, country))
    assert resp.status_code == 200, resp.text
    return resp.json()["items"]


@pytest.fixture
def inviter() -> int:
    return new_user_id()


def test_invited_registration_creates_reward(api: httpx.Client, publish: Publish, inviter: int) -> None:
    create_referral_campaign(api, "invite-friend-test", "XJ", 400)
    invitee = new_user_id()

    register(publish, invitee, "XJ", inviter)

    items = eventually(lambda: rewards(api, inviter, "XJ"), what="reward for the invite")
    assert [(r["invitee_id"], r["amount"]) for r in items] == [(invitee, 400)]


def test_reward_is_paid_out(api: httpx.Client, publish: Publish, mock: MockServer, inviter: int) -> None:
    create_referral_campaign(api, "invite-payout-test", "XK", 350)
    invitee = new_user_id()

    register(publish, invitee, "XK", inviter)

    eventually(
        lambda: [r for r in rewards(api, inviter, "XK") if r["status"] == "paid"],
        timeout=60,
        what="paid reward",
    )
    payouts = [r.body for r in mock.requests("POST", "/v1/payouts") if r.body["user_id"] == inviter]
    assert len(payouts) == 1
    assert payouts[0]["amount"] == 350
    assert payouts[0]["purpose"] == "referral_reward"
