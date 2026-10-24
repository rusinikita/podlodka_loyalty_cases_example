from dataclasses import dataclass
from datetime import datetime

from pydantic import BaseModel, Field, field_serializer

from loyalty.referrals.status import status_name


class Reward(BaseModel):
    """A row of referral_rewards. user_id is the inviter who gets the money,
    invitee_id is the invited friend."""

    id: int
    campaign_id: int = Field(exclude=True)
    user_id: int = Field(exclude=True)
    invitee_id: int
    amount: int
    status: int
    status_changed_at: datetime
    payout_id: str | None = Field(exclude=True)
    created_at: datetime

    @field_serializer("status")
    def _status(self, v: int) -> str:
        return status_name(v)


@dataclass
class NewReward:
    campaign_id: int
    user_id: int
    invitee_id: int
    amount: int
