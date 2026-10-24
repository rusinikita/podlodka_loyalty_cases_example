from collections.abc import Iterator
from contextlib import contextmanager
from typing import Annotated, Any

from fastapi import APIRouter, Depends
from pydantic import BaseModel, ConfigDict

from loyalty.admin.roles import ROLE_LOYALTY_ADMIN, ROLE_SUPPORT_L2, Operator, current_operator, require_role
from loyalty.campaigns.admin import CampaignInput, SearchFilter
from loyalty.campaigns.campaign import (
    Campaign,
    CampaignConflict,
    CampaignNotFound,
    InvalidCampaign,
    RewardType,
    Trigger,
)
from loyalty.campaigns.service import CampaignService
from loyalty.discounts.discount import CommentRequired, CompensationLimit, CompensationRequest, Discount
from loyalty.discounts.service import DiscountService
from loyalty.http import ApiError, bad_request, next_cursor, parse_cursor
from loyalty.referrals.service import ReferralService

PAGE_SIZE = 50

CurrentOperator = Annotated[Operator, Depends(current_operator)]


class CampaignWithActivations(Campaign):
    activations: int


class CompensationBody(BaseModel):
    model_config = ConfigDict(extra="forbid")

    percent: int
    max_amount: int | None = None
    ttl_days: int
    comment: str = ""


def admin_router(
    campaigns: CampaignService,
    discounts: DiscountService,
    referrals: ReferralService,
    compensation_role: str,
) -> APIRouter:
    router = APIRouter(dependencies=[Depends(current_operator)])

    @router.get("/campaigns")
    async def search_campaigns(
        query: str = "",
        trigger: Trigger | None = None,
        active: bool | None = None,
        cursor: str | None = None,
    ) -> dict[str, Any]:
        items = await campaigns.search(
            SearchFilter(query=query, trigger=trigger, active=active, after_id=parse_cursor(cursor), limit=PAGE_SIZE)
        )
        return {"items": items, "next_cursor": next_cursor(items, PAGE_SIZE)}

    @router.post("/campaigns", status_code=201, dependencies=[Depends(require_role(ROLE_LOYALTY_ADMIN))])
    async def create_campaign(body: CampaignInput, op: CurrentOperator) -> Campaign:
        with _campaign_errors():
            return await campaigns.create(body, op.login)

    @router.get("/campaigns/{campaign_id}", dependencies=[Depends(require_role(ROLE_SUPPORT_L2))])
    async def get_campaign(campaign_id: int) -> CampaignWithActivations:
        _positive(campaign_id, "id")
        with _campaign_errors():
            c = await campaigns.get(campaign_id)

        match c.reward_type:
            case RewardType.DISCOUNT:
                activations = await discounts.count_by_campaign(c.id)
            case RewardType.REFERRAL:
                activations = await referrals.count_by_campaign(c.id)
            case _:
                activations = 0
        return CampaignWithActivations(**dict(c), activations=activations)

    @router.patch("/campaigns/{campaign_id}", dependencies=[Depends(require_role(ROLE_LOYALTY_ADMIN))])
    async def update_campaign(campaign_id: int, body: CampaignInput, op: CurrentOperator) -> Campaign:
        _positive(campaign_id, "id")
        with _campaign_errors():
            return await campaigns.update(campaign_id, body, op.login)

    @router.get("/users/{user_id}/discounts")
    async def user_discounts(user_id: int, cursor: str | None = None) -> dict[str, Any]:
        _positive(user_id, "user_id")
        items = await discounts.by_user(user_id, parse_cursor(cursor), PAGE_SIZE)
        return {"items": items, "next_cursor": next_cursor(items, PAGE_SIZE)}

    @router.post(
        "/users/{user_id}/discounts",
        status_code=201,
        dependencies=[Depends(require_role(compensation_role))],
    )
    async def grant_compensation(user_id: int, body: CompensationBody, op: CurrentOperator) -> Discount:
        _positive(user_id, "user_id")
        try:
            return await discounts.grant_compensation(
                CompensationRequest(
                    user_id=user_id,
                    percent=body.percent,
                    max_amount=body.max_amount,
                    ttl_days=body.ttl_days,
                    operator=op.login,
                    comment=body.comment,
                )
            )
        except CompensationLimit as e:
            raise ApiError(422, "compensation_limit") from e
        except CommentRequired as e:
            raise bad_request(str(e)) from e

    @router.get("/users/{user_id}/referral-rewards")
    async def user_referral_rewards(user_id: int) -> dict[str, Any]:
        _positive(user_id, "user_id")
        return {"items": await referrals.by_user(user_id, 0, PAGE_SIZE)}

    return router


@contextmanager
def _campaign_errors() -> Iterator[None]:
    try:
        yield
    except CampaignNotFound as e:
        raise ApiError(404, "not_found") from e
    except CampaignConflict as e:
        raise ApiError(409, "conflict", str(e)) from e
    except InvalidCampaign as e:
        raise bad_request(str(e)) from e


def _positive(value: int, name: str) -> None:
    if value <= 0:
        raise bad_request(f"invalid {name}")
