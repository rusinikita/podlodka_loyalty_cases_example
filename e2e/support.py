import itertools
import time
import uuid
from collections.abc import Callable
from datetime import UTC, datetime, timedelta
from typing import Any

import httpx

ORDERS_TOKEN = "e2e-orders-token"

ADMIN = {"X-Operator-Login": "e2e.admin", "X-Operator-Role": "loyalty_admin"}
OPERATOR = {"X-Operator-Login": "e2e.operator", "X-Operator-Role": "loyalty_operator"}
ORDERS = {"X-Service-Token": ORDERS_TOKEN}

PAYMENTS_TOPIC = "payments.payment-succeeded"
REGISTRATIONS_TOPIC = "users.user-registered"

_user_ids = itertools.count(910_000_001)
_order_ids = itertools.count(5_400_000_001)


def new_user_id() -> int:
    return next(_user_ids)


def new_order_id() -> int:
    return next(_order_ids)


def iso(dt: datetime) -> str:
    return dt.astimezone(UTC).isoformat().replace("+00:00", "Z")


def now() -> datetime:
    return datetime.now(UTC)


def user_headers(user_id: int, country: str, city_id: int = 1) -> dict[str, str]:
    return {"X-User-Id": str(user_id), "X-User-Country": country, "X-User-City-Id": str(city_id)}


def create_campaign(api: httpx.Client, **fields: Any) -> dict[str, Any]:
    body = {"starts_at": iso(now() - timedelta(hours=1)), **fields}
    resp = api.post("/admin/v1/campaigns", json=body, headers=ADMIN)
    assert resp.status_code == 201, resp.text
    return resp.json()


def create_promo_campaign(api: httpx.Client, code: str, country: str, **reward: Any) -> dict[str, Any]:
    return create_campaign(
        api,
        slug=code.lower(),
        trigger="promo_code",
        promo_code=code,
        conditions={"countries": [country], "per_user_limit": 1},
        reward_type="discount",
        reward_params={"ttl_days": 7, **reward},
    )


def apply_promo(api: httpx.Client, user_id: int, country: str, code: str) -> httpx.Response:
    return api.post(
        "/v1/promo/apply",
        json={"code": code, "channel": "manual"},
        headers=user_headers(user_id, country),
    )


def active_discounts(api: httpx.Client, user_id: int, country: str) -> list[dict[str, Any]]:
    resp = api.get("/v1/discounts/active", headers=user_headers(user_id, country))
    assert resp.status_code == 200, resp.text
    return resp.json()["items"]


def event_id() -> str:
    return str(uuid.uuid4())


def eventually[T](check: Callable[[], T | None], timeout: float = 30, what: str = "condition") -> T:
    """Calls check until it returns a truthy value and returns that value."""
    deadline = time.monotonic() + timeout
    while True:
        value = check()
        if value:
            return value
        if time.monotonic() > deadline:
            raise AssertionError(f"{what}: not met after {timeout:.0f}s")
        time.sleep(0.5)
