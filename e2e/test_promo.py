import httpx

from support import active_discounts, apply_promo, create_promo_campaign, new_user_id


def test_apply_promo_code(api: httpx.Client) -> None:
    campaign = create_promo_campaign(api, "WELCOMETEST10", "XB", percent=10, max_amount=300)
    user_id = new_user_id()

    resp = apply_promo(api, user_id, "XB", "WELCOMETEST10")

    assert resp.status_code == 200, resp.text
    discount = resp.json()
    assert discount["campaign_id"] == campaign["id"]
    assert discount["percent"] == 10
    assert discount["max_amount"] == 300
    assert [d["id"] for d in active_discounts(api, user_id, "XB")] == [discount["id"]]


def test_promo_code_of_another_country(api: httpx.Client) -> None:
    create_promo_campaign(api, "CITYTEST200", "XC", amount=200)
    user_id = new_user_id()

    resp = apply_promo(api, user_id, "XD", "CITYTEST200")

    assert resp.status_code == 422, resp.text
    assert resp.json()["error"] == "promo_country_unavailable"
    assert active_discounts(api, user_id, "XD") == []


def test_promo_code_applied_twice(api: httpx.Client) -> None:
    create_promo_campaign(api, "ONCETEST150", "XE", amount=150)
    user_id = new_user_id()
    assert apply_promo(api, user_id, "XE", "ONCETEST150").status_code == 200

    resp = apply_promo(api, user_id, "XE", "ONCETEST150")

    assert resp.status_code == 409, resp.text
    assert resp.json()["error"] == "promo_already_used"
    assert len(active_discounts(api, user_id, "XE")) == 1
