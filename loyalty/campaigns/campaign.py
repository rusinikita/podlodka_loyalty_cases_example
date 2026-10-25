from dataclasses import dataclass, field
from datetime import datetime
from enum import StrEnum
from typing import Any

from pydantic import BaseModel, field_serializer


class Trigger(StrEnum):
    PROMO_CODE = "promo_code"
    PAYMENT_SUCCEEDED = "payment_succeeded"
    USER_REGISTERED = "user_registered"


class RewardType(StrEnum):
    DISCOUNT = "discount"
    REFERRAL = "referral_reward"


SEGMENT_ALL = "all"
RECIPIENT_INVITER = "inviter"


class CampaignNotFound(Exception):
    pass


class CampaignConflict(Exception):
    def __init__(self) -> None:
        super().__init__("slug or promo code already exists")


class InvalidCampaign(ValueError):
    pass


class CampaignExpired(Exception):
    pass


class MonthlyLimitReached(Exception):
    pass


class Ineligible(Exception):
    """A grant condition of the campaign does not match; reason tells which one."""

    def __init__(self, reason: str) -> None:
        super().__init__(f"not eligible: {reason}")
        self.reason = reason


class WrongCountry(Ineligible):
    def __init__(self) -> None:
        super().__init__("country")


class AlreadyUsed(Ineligible):
    def __init__(self) -> None:
        super().__init__("per_user_limit")


class ActivationsExhausted(Ineligible):
    def __init__(self) -> None:
        super().__init__("max_activations")


class Conditions(BaseModel):
    """Conditions of a campaign. Grant conditions are checked by campaigns when
    the trigger fires; order conditions (time_from, time_to, min_order_amount)
    are checked by discounts when the discount is used."""

    countries: list[str] | None = None
    segment: str | None = None
    city_ids: list[int] | None = None
    per_user_limit: int | None = None
    max_activations: int | None = None
    sku_ids: list[int] | None = None
    payment_methods: list[str] | None = None
    invited: bool | None = None
    first_order: bool | None = None
    time_from: str | None = None
    time_to: str | None = None
    min_order_amount: int | None = None


class RewardParams(BaseModel):
    """Of a discount: percent with an optional max_amount cap, or a fixed
    amount; ttl_days, or until the end of the campaign if not set.
    Of a referral reward: amount and recipient."""

    percent: int | None = None
    amount: int | None = None
    max_amount: int | None = None
    ttl_days: int | None = None
    recipient: str | None = None


class Campaign(BaseModel):
    id: int
    slug: str
    trigger: Trigger
    promo_code: str | None
    conditions: Conditions
    reward_type: RewardType
    reward_params: RewardParams
    starts_at: datetime
    ends_at: datetime | None
    is_active: bool
    created_by: str | None = None
    created_at: datetime

    @field_serializer("conditions", "reward_params")
    def _compact(self, v: BaseModel) -> dict[str, Any]:
        return v.model_dump(exclude_none=True)


@dataclass
class Event:
    """What fired the trigger: a promo code entered in the app, a payment or
    a registration. Fields that the trigger does not carry are empty."""

    trigger: Trigger
    user_id: int
    country: str = ""
    city_id: int = 0
    segment: str = ""

    # promo_code
    channel: str = ""

    # payment_succeeded
    payment_id: str = ""
    payment_method: str = ""
    sku_ids: list[int] = field(default_factory=list)

    # user_registered; 0 if the user was not invited
    invited_by: int = 0
