"""Client of the CRM notifications service (api/notifications.yaml)."""

from dataclasses import asdict, dataclass, field

import httpx


@dataclass
class Push:
    user_id: int
    template: str
    params: dict[str, str] = field(default_factory=dict)


class NotificationsError(Exception):
    pass


class NotificationsClient:
    def __init__(self, base_url: str, token: str, timeout: float) -> None:
        self._http = httpx.AsyncClient(
            base_url=base_url,
            headers={"X-Service-Token": token},
            timeout=timeout,
        )

    async def push(self, p: Push) -> None:
        try:
            resp = await self._http.post("/v1/push", json=asdict(p))
        except httpx.HTTPError as e:
            raise NotificationsError(f"notifications: {e}") from e
        if resp.status_code != httpx.codes.ACCEPTED:
            raise NotificationsError(f"notifications: unexpected status {resp.status_code}")

    async def aclose(self) -> None:
        await self._http.aclose()
