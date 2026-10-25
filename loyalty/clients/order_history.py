"""Client of the order history service (api/order-history.yaml)."""

import httpx
from pydantic import BaseModel


class OrderHistoryError(Exception):
    def __init__(self, status_code: int, code: str = "", message: str = "") -> None:
        super().__init__(f"order-history: {status_code} {code}: {message}")
        self.status_code = status_code
        self.code = code


class OrdersCount(BaseModel):
    count: int


class OrderHistoryClient:
    def __init__(self, base_url: str, token: str, timeout: float) -> None:
        self._http = httpx.AsyncClient(
            base_url=base_url,
            headers={"X-Service-Token": token},
            timeout=timeout,
        )

    async def count_orders(self, user_id: int) -> int:
        """The number of the user's paid and completed orders for all time, of
        every channel and payment method. An unknown user has none."""
        resp = await self._http.get(f"/v1/users/{user_id}/orders/count")
        if resp.status_code == httpx.codes.OK:
            return OrdersCount.model_validate_json(resp.content).count
        if resp.status_code == httpx.codes.NOT_FOUND:
            return 0

        try:
            body = resp.json()
        except ValueError:
            body = {}
        raise OrderHistoryError(resp.status_code, body.get("code", ""), body.get("message", ""))

    async def aclose(self) -> None:
        await self._http.aclose()
