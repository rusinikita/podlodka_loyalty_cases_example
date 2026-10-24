from dataclasses import dataclass, field
from datetime import datetime

from pydantic import BaseModel, ConfigDict

from loyalty.campaigns.campaign import (
    Campaign,
    Conditions,
    InvalidCampaign,
    RewardParams,
    RewardType,
    Trigger,
)


@dataclass
class SearchFilter:
    query: str = ""
    trigger: Trigger | None = None
    active: bool | None = None
    after_id: int = 0
    limit: int = 50


class CampaignInput(BaseModel):
    """A campaign from the admin panel. On update only the set fields are changed."""

    model_config = ConfigDict(extra="forbid")

    slug: str | None = None
    trigger: Trigger | None = None
    promo_code: str | None = None
    conditions: Conditions | None = None
    reward_type: RewardType | None = None
    reward_params: RewardParams | None = None
    starts_at: datetime | None = None
    ends_at: datetime | None = None
    is_active: bool | None = None


@dataclass
class CampaignDraft:
    """The editable fields of a campaign, saved by the repository."""

    slug: str = ""
    trigger: Trigger | None = None
    promo_code: str | None = None
    conditions: Conditions = field(default_factory=Conditions)
    reward_type: RewardType | None = None
    reward_params: RewardParams = field(default_factory=RewardParams)
    starts_at: datetime | None = None
    ends_at: datetime | None = None
    is_active: bool = True
    created_by: str | None = None

    @classmethod
    def of(cls, c: Campaign) -> "CampaignDraft":
        return cls(
            slug=c.slug,
            trigger=c.trigger,
            promo_code=c.promo_code,
            conditions=c.conditions,
            reward_type=c.reward_type,
            reward_params=c.reward_params,
            starts_at=c.starts_at,
            ends_at=c.ends_at,
            is_active=c.is_active,
            created_by=c.created_by,
        )

    def apply(self, inp: CampaignInput) -> None:
        if inp.slug is not None:
            self.slug = inp.slug
        if inp.trigger is not None:
            self.trigger = inp.trigger
        if inp.promo_code is not None:
            self.promo_code = inp.promo_code
        if inp.conditions is not None:
            self.conditions = inp.conditions
        if inp.reward_type is not None:
            self.reward_type = inp.reward_type
        if inp.reward_params is not None:
            self.reward_params = inp.reward_params
        if inp.starts_at is not None:
            self.starts_at = inp.starts_at
        if inp.ends_at is not None:
            self.ends_at = inp.ends_at
        if inp.is_active is not None:
            self.is_active = inp.is_active

    def validate(self) -> None:
        if not self.slug:
            raise InvalidCampaign("slug is required")
        match self.trigger:
            case Trigger.PROMO_CODE:
                if not self.promo_code:
                    raise InvalidCampaign(f"promo_code is required for trigger {self.trigger}")
            case Trigger.PAYMENT_SUCCEEDED | Trigger.USER_REGISTERED:
                pass
            case _:
                raise InvalidCampaign(f"unknown trigger {self.trigger!r}")
        p = self.reward_params
        match self.reward_type:
            case RewardType.DISCOUNT:
                if not p.percent and not p.amount:
                    raise InvalidCampaign("percent or amount is required")
            case RewardType.REFERRAL:
                if not p.amount:
                    raise InvalidCampaign("amount is required")
            case _:
                raise InvalidCampaign(f"unknown reward type {self.reward_type!r}")
        if self.starts_at is None:
            raise InvalidCampaign("starts_at is required")
