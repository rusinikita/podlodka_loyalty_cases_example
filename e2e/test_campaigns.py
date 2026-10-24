import httpx

from support import ADMIN, create_promo_campaign


def test_create_campaign_and_read_card(api: httpx.Client) -> None:
    created = create_promo_campaign(api, "AUTUMNTEST15", "XA", percent=15, max_amount=400)

    assert created["slug"] == "autumntest15"
    assert created["trigger"] == "promo_code"
    assert created["is_active"] is True
    assert created["created_by"] == ADMIN["X-Operator-Login"]

    resp = api.get(f"/admin/v1/campaigns/{created['id']}", headers=ADMIN)
    assert resp.status_code == 200, resp.text
    card = resp.json()
    assert card["promo_code"] == "AUTUMNTEST15"
    assert card["conditions"]["countries"] == ["XA"]
    assert card["reward_type"] == "discount"
    assert card["reward_params"]["percent"] == 15
    assert card["reward_params"]["max_amount"] == 400
    assert card["activations"] == 0
