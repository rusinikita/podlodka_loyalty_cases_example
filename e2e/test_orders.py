import httpx

from support import (
    ADMIN,
    ORDERS,
    active_discounts,
    apply_promo,
    create_promo_campaign,
    iso,
    new_order_id,
    new_user_id,
    now,
)


def test_discount_quote_for_order(api: httpx.Client) -> None:
    create_promo_campaign(api, "QUOTETEST12", "XF", percent=12, max_amount=500)
    user_id = new_user_id()
    discount = apply_promo(api, user_id, "XF", "QUOTETEST12").json()

    resp = api.post(
        "/v1/orders/discount-quote",
        json={"user_id": user_id, "order_amount": 1500, "city_id": 1, "ordered_at": iso(now())},
        headers=ORDERS,
    )

    assert resp.status_code == 200, resp.text
    assert resp.json() == {"discount_id": discount["id"], "discount_amount": 180}


def test_redeem_discount_for_paid_order(api: httpx.Client) -> None:
    create_promo_campaign(api, "REDEEMTEST250", "XG", amount=250)
    user_id = new_user_id()
    order_id = new_order_id()
    discount = apply_promo(api, user_id, "XG", "REDEEMTEST250").json()

    resp = api.post(
        f"/v1/orders/{order_id}/redeem-discount",
        json={"discount_id": discount["id"], "user_id": user_id},
        headers=ORDERS,
    )

    assert resp.status_code == 204, resp.text
    assert active_discounts(api, user_id, "XG") == []
    history = api.get(f"/admin/v1/users/{user_id}/discounts", headers=ADMIN).json()["items"]
    assert [d["id"] for d in history] == [discount["id"]]
    assert history[0]["used_at"] is not None
