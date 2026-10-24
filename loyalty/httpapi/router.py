import hmac
from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, Request, Response
from pydantic import BaseModel, ConfigDict

from loyalty.discounts.discount import Discount, DiscountUnavailable, QuoteRequest
from loyalty.discounts.service import DiscountService
from loyalty.http import ApiError, next_cursor, parse_cursor
from loyalty.httpapi.user import User, user_from_headers
from loyalty.promo.service import ApplyRequest, Channel, PromoError, PromoService
from loyalty.referrals.service import ReferralService

PAGE_SIZE = 50

PROMO_ERROR_STATUS = {
    "promo_not_found": 404,
    "promo_already_used": 409,
    "promo_expired": 422,
    "promo_activations_exhausted": 422,
    "promo_monthly_limit": 422,
    "promo_country_unavailable": 422,
}


class Body(BaseModel):
    model_config = ConfigDict(extra="forbid")


class ApplyPromoBody(Body):
    code: str
    channel: Channel


class QuoteBody(Body):
    user_id: int
    order_amount: int
    city_id: int = 0
    ordered_at: datetime


class RedeemBody(Body):
    discount_id: int
    user_id: int


CurrentUser = Annotated[User, Depends(user_from_headers)]


def app_router(
    promo: PromoService,
    discounts: DiscountService,
    referrals: ReferralService,
    service_token: str,
) -> APIRouter:
    router = APIRouter()

    @router.post("/promo/apply")
    async def apply_promo(user: CurrentUser, body: ApplyPromoBody) -> Discount:
        try:
            return await promo.apply(
                ApplyRequest(
                    user_id=user.id,
                    country=user.country,
                    city_id=user.city_id,
                    segment=user.segment,
                    code=body.code,
                    channel=body.channel,
                )
            )
        except PromoError as e:
            raise ApiError(PROMO_ERROR_STATUS.get(e.code, 422), e.code) from e

    @router.get("/discounts/active")
    async def active_discounts(user: CurrentUser) -> dict[str, list[Discount]]:
        return {"items": await discounts.active(user.id)}

    @router.get("/referrals/rewards")
    async def referral_rewards(user: CurrentUser, cursor: str | None = None) -> dict[str, Any]:
        items = await referrals.by_user(user.id, parse_cursor(cursor), PAGE_SIZE)
        return {"items": items, "next_cursor": next_cursor(items, PAGE_SIZE)}

    def require_service_token(request: Request) -> None:
        got = request.headers.get("X-Service-Token", "")
        if not hmac.compare_digest(got.encode(), service_token.encode()):
            raise ApiError(401, "unauthorized")

    orders = APIRouter(prefix="/orders", dependencies=[Depends(require_service_token)])

    @orders.post("/discount-quote")
    async def quote_discount(body: QuoteBody) -> dict[str, int | None]:
        q = await discounts.quote(
            QuoteRequest(
                user_id=body.user_id,
                order_amount=body.order_amount,
                city_id=body.city_id,
                ordered_at=body.ordered_at,
            )
        )
        return {"discount_id": q.discount_id, "discount_amount": q.amount}

    @orders.post("/{order_id}/redeem-discount", status_code=204)
    async def redeem_discount(order_id: int, body: RedeemBody) -> Response:
        try:
            await discounts.redeem(body.discount_id, body.user_id, order_id)
        except DiscountUnavailable as e:
            raise ApiError(409, "discount_unavailable") from e
        return Response(status_code=204)

    router.include_router(orders)
    return router
