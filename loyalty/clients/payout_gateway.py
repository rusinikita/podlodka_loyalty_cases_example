"""Client of payout-gateway (api/payout-gateway.yaml)."""

from dataclasses import asdict, dataclass
from datetime import datetime

import httpx
from pydantic import BaseModel


class PayoutError(Exception):
    """A response the client does not map to a more specific error."""

    def __init__(self, status_code: int, code: str = "", message: str = "") -> None:
        super().__init__(f"payout-gateway: {status_code} {code}: {message}")
        self.status_code = status_code
        self.code = code


class NoPayoutMethod(PayoutError):
    pass


class UserBlocked(PayoutError):
    pass


@dataclass
class PayoutRequest:
    idempotency_key: str
    user_id: int
    amount: int
    currency: str
    purpose: str


class Payout(BaseModel):
    payout_id: str
    status: str
    card_mask: str
    created_at: datetime


class PayoutGatewayClient:
    def __init__(self, base_url: str, token: str, timeout: float) -> None:
        self._http = httpx.AsyncClient(
            base_url=base_url,
            headers={"X-Service-Token": token},
            timeout=timeout,
        )

    async def pay(self, req: PayoutRequest) -> Payout:
        """Creates a payout. A repeated call with the same idempotency key
        returns the original payout."""
        resp = await self._http.post("/v1/payouts", json=asdict(req))
        if resp.status_code == httpx.codes.OK:
            return Payout.model_validate_json(resp.content)

        try:
            body = resp.json()
        except ValueError:
            body = {}
        code, message = body.get("code", ""), body.get("message", "")
        match code:
            case "no_payout_method":
                raise NoPayoutMethod(resp.status_code, code, message)
            case "user_blocked":
                raise UserBlocked(resp.status_code, code, message)
        raise PayoutError(resp.status_code, code, message)

    async def aclose(self) -> None:
        await self._http.aclose()
