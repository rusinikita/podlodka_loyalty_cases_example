"""Client of the antifraud verdicts API (api/antifraud.yaml)."""

from datetime import datetime
from enum import StrEnum

import httpx
from pydantic import BaseModel

# The largest number of rewards accepted in one request.
MAX_BATCH = 500

SOURCE = "loyalty"


class Verdict(StrEnum):
    APPROVED = "approved"
    REJECTED = "rejected"
    PENDING = "pending"


class RewardCheck(BaseModel):
    reward_id: int
    inviter_id: int
    invitee_id: int
    amount: int
    currency: str
    created_at: datetime


class RewardVerdict(BaseModel):
    reward_id: int
    verdict: Verdict
    reason: str | None = None
    checked_at: datetime | None = None


class _VerdictsResponse(BaseModel):
    items: list[RewardVerdict]


class AntifraudError(Exception):
    """A non-200 response of the antifraud service."""

    def __init__(self, status_code: int, code: str = "", message: str = "") -> None:
        super().__init__(f"antifraud: {status_code} {code}: {message}")
        self.status_code = status_code
        self.code = code


class AntifraudClient:
    def __init__(self, base_url: str, token: str, timeout: float) -> None:
        self._http = httpx.AsyncClient(
            base_url=base_url,
            headers={"X-Service-Token": token},
            timeout=timeout,
        )

    async def verdicts(self, items: list[RewardCheck]) -> list[RewardVerdict]:
        """Verdicts for up to MAX_BATCH rewards, in the order of the request.
        A reward antifraud has not seen yet gets Verdict.PENDING and a check is
        started for it."""
        if not items:
            return []
        if len(items) > MAX_BATCH:
            raise ValueError(f"antifraud: batch of {len(items)} rewards, at most {MAX_BATCH} allowed")

        resp = await self._http.post(
            "/v1/referral-rewards/verdicts",
            json={"source": SOURCE, "items": [it.model_dump(mode="json") for it in items]},
        )
        if resp.status_code != httpx.codes.OK:
            try:
                body = resp.json()
            except ValueError:
                body = {}
            raise AntifraudError(resp.status_code, body.get("code", ""), body.get("message", ""))

        out = _VerdictsResponse.model_validate_json(resp.content).items
        if len(out) != len(items):
            raise ValueError(f"antifraud: got {len(out)} verdicts for {len(items)} rewards")
        return out

    async def aclose(self) -> None:
        await self._http.aclose()
