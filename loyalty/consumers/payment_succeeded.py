from datetime import datetime
from typing import Protocol

import structlog
from pydantic import BaseModel, ValidationError

from loyalty.campaigns.campaign import Event, Trigger
from loyalty.kafka.consumer import Message

log = structlog.get_logger()


class CampaignHandler(Protocol):
    async def handle(self, ev: Event) -> None: ...


class PaymentItem(BaseModel):
    sku_id: int = 0
    quantity: int = 0
    price: int = 0


class PaymentSucceededEvent(BaseModel):
    """Payload of payments.payment-succeeded (api/events/payment-succeeded.yaml)."""

    event_id: str = ""
    occurred_at: datetime | None = None
    payment_id: str = ""
    order_id: int = 0
    user_id: int = 0
    country: str = ""
    city_id: int = 0
    user_segment: str = ""
    amount: int = 0
    currency: str = ""
    payment_method: str = ""
    items: list[PaymentItem] = []


class PaymentSucceededHandler:
    def __init__(self, campaigns: CampaignHandler) -> None:
        self._campaigns = campaigns

    async def handle(self, msg: Message) -> None:
        """Runs the payment_succeeded campaigns for a paid order."""
        try:
            evt = PaymentSucceededEvent.model_validate_json(msg.value)
        except ValidationError as e:
            # Redelivery will not fix a broken payload.
            log.error("malformed payment event", partition=msg.partition, offset=msg.offset, err=str(e))
            return
        if not evt.user_id or not evt.payment_id:
            log.error("incomplete payment event", event_id=evt.event_id, partition=msg.partition, offset=msg.offset)
            return

        await self._campaigns.handle(
            Event(
                trigger=Trigger.PAYMENT_SUCCEEDED,
                user_id=evt.user_id,
                country=evt.country,
                city_id=evt.city_id,
                segment=evt.user_segment,
                payment_id=evt.payment_id,
                payment_method=evt.payment_method,
                sku_ids=[it.sku_id for it in evt.items],
            )
        )
