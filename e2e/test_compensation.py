import httpx

from support import ADMIN, OPERATOR, active_discounts, new_user_id


def test_operator_grants_compensation(api: httpx.Client) -> None:
    user_id = new_user_id()

    resp = api.post(
        f"/admin/v1/users/{user_id}/discounts",
        json={"percent": 15, "max_amount": 600, "ttl_days": 7, "comment": "Обращение #770512"},
        headers=OPERATOR,
    )

    assert resp.status_code == 201, resp.text
    discount = resp.json()
    assert discount["campaign_id"] is None
    assert discount["percent"] == 15
    assert discount["max_amount"] == 600
    assert [d["id"] for d in active_discounts(api, user_id, "XI")] == [discount["id"]]
    history = api.get(f"/admin/v1/users/{user_id}/discounts", headers=ADMIN).json()["items"]
    assert [d["id"] for d in history] == [discount["id"]]
