from datetime import UTC, datetime, timedelta
from typing import Protocol

import structlog

from loyalty.clients.notifications import Push
from loyalty.config.app import CompensationConfig
from loyalty.discounts.discount import (
    CommentRequired,
    CompensationLimit,
    CompensationRequest,
    Discount,
    DiscountUnavailable,
    GrantRequest,
    Quote,
    QuoteRequest,
)
from loyalty.discounts.repository import PostgresDiscountRepository

NEXT_ORDER_PUSH_TEMPLATE = "loyalty_next_order_discount"

log = structlog.get_logger()


class Notifier(Protocol):
    async def push(self, p: Push) -> None: ...


class DiscountService:
    def __init__(
        self,
        repo: PostgresDiscountRepository,
        notify: Notifier,
        compensation: CompensationConfig,
    ) -> None:
        self._repo = repo
        self._notify = notify
        self._compensation = compensation

    async def grant(self, req: GrantRequest) -> Discount:
        """Saves a discount of a campaign. With notify the user also gets a push
        about the discount for the next order."""
        d = await self._repo.insert(
            user_id=req.user_id,
            campaign_id=req.campaign_id,
            channel=req.channel,
            payment_id=req.payment_id,
            percent=req.percent,
            amount=req.amount,
            max_amount=req.max_amount,
            expires_at=req.expires_at,
        )

        if req.notify:
            await self._push_next_order(d)

        log.info("discount granted", discount_id=d.id, user_id=d.user_id, campaign_id=req.campaign_id)
        return d

    async def _push_next_order(self, d: Discount) -> None:
        params = {"expires_at": d.expires_at.date().isoformat()}
        if d.percent is not None:
            params["percent"] = str(d.percent)
        if d.amount is not None:
            params["amount"] = str(d.amount)
        await self._notify.push(Push(user_id=d.user_id, template=NEXT_ORDER_PUSH_TEMPLATE, params=params))

    async def grant_compensation(self, req: CompensationRequest) -> Discount:
        """Saves a discount given by an operator. It has no campaign; the
        operator and the ticket number are kept in the row."""
        limits = self._compensation
        if not (1 <= req.percent <= limits.max_percent and 1 <= req.ttl_days <= limits.ttl_days):
            raise CompensationLimit()
        if not req.comment.strip():
            raise CommentRequired()

        d = await self._repo.insert(
            user_id=req.user_id,
            percent=req.percent,
            max_amount=req.max_amount,
            expires_at=datetime.now(UTC) + timedelta(days=req.ttl_days),
            created_by=req.operator,
            comment=req.comment,
        )
        log.info(
            "compensation granted",
            discount_id=d.id,
            user_id=d.user_id,
            operator=req.operator,
            percent=req.percent,
        )
        return d

    async def active(self, user_id: int) -> list[Discount]:
        return await self._repo.active(user_id, datetime.now(UTC))

    async def by_user(self, user_id: int, after_id: int, limit: int) -> list[Discount]:
        return await self._repo.by_user(user_id, after_id, limit)

    async def quote(self, req: QuoteRequest) -> Quote:
        """Picks the user's discount for an order. Discounts do not stack: the
        order gets the single largest one whose order conditions match."""
        candidates = await self._repo.redeemable(req.user_id, req.ordered_at)

        best = Quote()
        for c in candidates:
            if not c.conditions.match(req.order_amount, req.ordered_at):
                continue
            v = c.discount.value(req.order_amount)
            if v > best.amount:
                best = Quote(discount_id=c.discount.id, amount=v)
        return best

    async def redeem(self, discount_id: int, user_id: int, order_id: int) -> None:
        if not await self._repo.mark_used(discount_id, user_id, order_id):
            raise DiscountUnavailable()

    async def count_by_campaign(self, campaign_id: int) -> int:
        return await self._repo.count_by_campaign(campaign_id)

    async def count_by_user(self, campaign_id: int, user_id: int) -> int:
        return await self._repo.count_by_user(campaign_id, user_id)

    async def count_promo_used_this_month(self, user_id: int) -> int:
        return await self._repo.count_promo_used_this_month(user_id)
