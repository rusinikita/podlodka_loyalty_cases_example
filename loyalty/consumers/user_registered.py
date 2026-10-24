from datetime import datetime

import structlog
from pydantic import BaseModel, ValidationError

from loyalty.campaigns.campaign import Event, Trigger
from loyalty.consumers.payment_succeeded import CampaignHandler
from loyalty.kafka.consumer import Message

log = structlog.get_logger()


class UserRegisteredEvent(BaseModel):
    """Payload of users.user-registered (api/events/user-registered.yaml)."""

    event_id: str = ""
    occurred_at: datetime | None = None
    user_id: int = 0
    country: str = ""
    city_id: int = 0
    invited_by: int | None = None


class UserRegisteredHandler:
    def __init__(self, campaigns: CampaignHandler) -> None:
        self._campaigns = campaigns

    async def handle(self, msg: Message) -> None:
        """Runs the user_registered campaigns for a new user."""
        try:
            evt = UserRegisteredEvent.model_validate_json(msg.value)
        except ValidationError as e:
            log.error("malformed registration event", partition=msg.partition, offset=msg.offset, err=str(e))
            return
        if not evt.user_id:
            log.error(
                "incomplete registration event", event_id=evt.event_id, partition=msg.partition, offset=msg.offset
            )
            return

        await self._campaigns.handle(
            Event(
                trigger=Trigger.USER_REGISTERED,
                user_id=evt.user_id,
                country=evt.country,
                city_id=evt.city_id,
                invited_by=evt.invited_by or 0,
            )
        )
