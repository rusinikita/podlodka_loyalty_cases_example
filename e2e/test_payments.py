from collections.abc import Callable
from typing import Any

import httpx

from mock_server import MockServer
from support import (
    PAYMENTS_TOPIC,
    active_discounts,
    create_campaign,
    event_id,
    eventually,
    iso,
    new_order_id,
    new_user_id,
    now,
)


def test_payment_grants_next_order_discount(
    api: httpx.Client, publish: Callable[[str, int, dict[str, Any]], None], mock: MockServer
) -> None:
    campaign = create_campaign(
        api,
        slug="next-order-test",
        trigger="payment_succeeded",
        conditions={"countries": ["XH"]},
        reward_type="discount",
        reward_params={"percent": 20, "max_amount": 350, "ttl_days": 10},
    )
    user_id = new_user_id()
    order_id = new_order_id()

    publish(
        PAYMENTS_TOPIC,
        user_id,
        {
            "event_id": event_id(),
            "event_type": "payment.succeeded",
            "occurred_at": iso(now()),
            "payment_id": f"pay_{order_id}",
            "order_id": order_id,
            "user_id": user_id,
            "country": "XH",
            "city_id": 1,
            "user_segment": "regular",
            "amount": 184000,
            "currency": "RUB",
            "payment_method": "card",
            "items": [{"sku_id": 70011, "quantity": 2, "price": 92000}],
        },
    )

    discounts = eventually(lambda: active_discounts(api, user_id, "XH"), what="discount after payment")
    assert [(d["campaign_id"], d["percent"]) for d in discounts] == [(campaign["id"], 20)]

    pushes = eventually(
        lambda: [r for r in mock.requests("POST", "/v1/push") if r.body["user_id"] == user_id],
        what="push after payment",
    )
    assert len(pushes) == 1
    assert pushes[0].body["template"] == "loyalty_next_order_discount"
    assert pushes[0].body["params"]["percent"] == "20"
