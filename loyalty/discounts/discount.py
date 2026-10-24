from dataclasses import dataclass
from datetime import datetime

from pydantic import BaseModel, Field


class DiscountUnavailable(Exception):
    def __init__(self) -> None:
        super().__init__("discount is used or expired")


class CompensationLimit(Exception):
    def __init__(self) -> None:
        super().__init__("compensation is above the limits")


class CommentRequired(ValueError):
    def __init__(self) -> None:
        super().__init__("comment with the ticket number is required")


class Discount(BaseModel):
    """A row of user_discounts. Either percent (with an optional max_amount
    cap) or amount is set."""

    id: int
    user_id: int = Field(exclude=True)
    campaign_id: int | None
    channel: str | None = Field(exclude=True)
    payment_id: str | None = Field(exclude=True)
    percent: int | None
    amount: int | None
    max_amount: int | None
    expires_at: datetime
    used_at: datetime | None
    used_order_id: int | None = Field(exclude=True)
    created_by: str | None = Field(exclude=True)
    comment: str | None = Field(exclude=True)
    created_at: datetime

    def value(self, order_amount: int) -> int:
        """The discount for an order of order_amount."""
        if self.percent is not None:
            v = order_amount * self.percent // 100
            if self.max_amount is not None and v > self.max_amount:
                v = self.max_amount
            return v
        if self.amount is not None:
            return min(self.amount, order_amount)
        return 0


@dataclass
class GrantRequest:
    """A discount granted by a campaign."""

    user_id: int
    campaign_id: int
    channel: str | None
    payment_id: str | None
    percent: int | None
    amount: int | None
    max_amount: int | None
    expires_at: datetime
    # Sends the user a push about the discount for the next order.
    notify: bool = False


@dataclass
class CompensationRequest:
    """A discount granted by an operator in the admin panel."""

    user_id: int
    percent: int
    max_amount: int | None
    ttl_days: int
    operator: str
    comment: str


@dataclass
class QuoteRequest:
    user_id: int
    order_amount: int
    city_id: int
    ordered_at: datetime


@dataclass
class Quote:
    discount_id: int | None = None
    amount: int = 0


class OrderConditions(BaseModel):
    """The campaign conditions checked when a discount is used for an order."""

    time_from: str | None = None
    time_to: str | None = None
    min_order_amount: int | None = None

    def match(self, order_amount: int, ordered_at: datetime) -> bool:
        if self.min_order_amount and order_amount < self.min_order_amount:
            return False
        if not self.time_from or not self.time_to:
            return True
        hm = ordered_at.strftime("%H:%M")
        if self.time_from <= self.time_to:
            return self.time_from <= hm < self.time_to
        # The interval crosses midnight, e.g. 22:00–06:00.
        return hm >= self.time_from or hm < self.time_to


@dataclass
class Redeemable:
    """An unused discount together with the order conditions of its campaign."""

    discount: Discount
    conditions: OrderConditions
