from datetime import UTC, datetime, timedelta
from typing import Protocol

import structlog

from loyalty.campaigns.admin import CampaignDraft, CampaignInput, SearchFilter
from loyalty.campaigns.campaign import (
    RECIPIENT_INVITER,
    SEGMENT_ALL,
    ActivationsExhausted,
    AlreadyUsed,
    Campaign,
    CampaignExpired,
    Event,
    Ineligible,
    MonthlyLimitReached,
    RewardType,
    Trigger,
    WrongCountry,
)
from loyalty.campaigns.repository import PostgresCampaignRepository
from loyalty.discounts.discount import Discount, GrantRequest
from loyalty.referrals.reward import NewReward

log = structlog.get_logger()


class Discounts(Protocol):
    async def grant(self, req: GrantRequest) -> Discount: ...
    async def count_by_campaign(self, campaign_id: int) -> int: ...
    async def count_by_user(self, campaign_id: int, user_id: int) -> int: ...
    async def count_promo_used_this_month(self, user_id: int) -> int: ...


class Referrals(Protocol):
    async def create(self, nr: NewReward) -> None: ...


class OrderHistory(Protocol):
    """Counts the user's paid and completed orders across all channels and
    payment methods."""

    async def count_orders(self, user_id: int) -> int: ...


class CampaignService:
    def __init__(
        self,
        repo: PostgresCampaignRepository,
        discounts: Discounts,
        referrals: Referrals,
        orders: OrderHistory,
        max_promo_per_month: int,
    ) -> None:
        self._repo = repo
        self._discounts = discounts
        self._referrals = referrals
        self._orders = orders
        self._max_promo_per_month = max_promo_per_month

    async def handle(self, ev: Event) -> None:
        """Processes a payment or a registration: every active campaign of the
        trigger whose grant conditions match the event grants its reward."""
        active = await self._repo.active_by_trigger(ev.trigger, datetime.now(UTC))

        matched: list[Campaign] = []
        for c in active:
            try:
                await self._check_grant(c, ev)
            except Ineligible as e:
                log.debug("campaign skipped", campaign=c.slug, user_id=ev.user_id, reason=e.reason)
                continue
            matched.append(c)

        for c in matched:
            await self._grant(c, ev)

    async def by_promo_code(self, code: str) -> Campaign:
        return await self._repo.by_promo_code(code)

    async def apply_promo_code(self, c: Campaign, ev: Event) -> Discount:
        """Grants the discount of a promo code campaign to the user who entered the code."""
        now = datetime.now(UTC)
        if not c.is_active or now < c.starts_at:
            raise Ineligible("inactive")
        if c.ends_at is not None and now >= c.ends_at:
            raise CampaignExpired()
        await self._check_grant(c, ev)

        used = await self._discounts.count_promo_used_this_month(ev.user_id)
        if used >= self._max_promo_per_month:
            raise MonthlyLimitReached()

        return await self._grant_discount(c, ev)

    async def get(self, campaign_id: int) -> Campaign:
        return await self._repo.by_id(campaign_id)

    async def search(self, f: SearchFilter) -> list[Campaign]:
        return await self._repo.search(f)

    async def create(self, inp: CampaignInput, operator: str) -> Campaign:
        draft = CampaignDraft(created_by=operator)
        draft.apply(inp)
        draft.validate()
        c = await self._repo.insert(draft)
        log.info("campaign created", campaign_id=c.id, slug=c.slug, operator=operator)
        return c

    async def update(self, campaign_id: int, inp: CampaignInput, operator: str) -> Campaign:
        draft = CampaignDraft.of(await self._repo.by_id(campaign_id))
        draft.apply(inp)
        draft.validate()
        c = await self._repo.update(campaign_id, draft)
        log.info("campaign updated", campaign_id=campaign_id, operator=operator)
        return c

    async def _check_grant(self, c: Campaign, ev: Event) -> None:
        """Raises Ineligible if a grant condition of c does not match the event."""
        cond = c.conditions

        if cond.countries and ev.country not in cond.countries:
            raise WrongCountry()
        if cond.segment and cond.segment != SEGMENT_ALL and cond.segment != ev.segment:
            raise Ineligible("segment")
        if cond.city_ids and ev.city_id not in cond.city_ids:
            raise Ineligible("city")
        if cond.sku_ids and not set(cond.sku_ids) & set(ev.sku_ids):
            raise Ineligible("sku")
        if cond.payment_methods and ev.payment_method not in cond.payment_methods:
            raise Ineligible("payment_method")
        if cond.invited and not ev.invited_by:
            raise Ineligible("not_invited")
        if cond.first_order and not await self._is_first_order(ev.user_id):
            raise Ineligible("not_first_order")

        if cond.per_user_limit:
            n = await self._discounts.count_by_user(c.id, ev.user_id)
            if n >= cond.per_user_limit:
                raise AlreadyUsed()
        if cond.max_activations:
            n = await self._discounts.count_by_campaign(c.id)
            if n >= cond.max_activations:
                raise ActivationsExhausted()

    async def _is_first_order(self, user_id: int) -> bool:
        """Whether the payment is for the user's first order: the order
        history, which already includes the paid order, has exactly one."""
        return await self._orders.count_orders(user_id) == 1

    async def _grant(self, c: Campaign, ev: Event) -> None:
        match c.reward_type:
            case RewardType.DISCOUNT:
                await self._grant_discount(c, ev)
            case RewardType.REFERRAL:
                await self._grant_referral(c, ev)
            case _:
                log.warning("unknown reward type", campaign=c.slug, reward_type=c.reward_type)

    async def _grant_discount(self, c: Campaign, ev: Event) -> Discount:
        p = c.reward_params
        if p.ttl_days:
            expires_at = datetime.now(UTC) + timedelta(days=p.ttl_days)
        elif c.ends_at is not None:
            expires_at = c.ends_at
        else:
            raise ValueError(f"campaign {c.slug}: neither ttl_days nor ends_at is set")

        return await self._discounts.grant(
            GrantRequest(
                user_id=ev.user_id,
                campaign_id=c.id,
                channel=ev.channel or None,
                payment_id=ev.payment_id or None,
                percent=p.percent,
                amount=p.amount,
                max_amount=p.max_amount,
                expires_at=expires_at,
                notify=c.trigger == Trigger.PAYMENT_SUCCEEDED,
            )
        )

    async def _grant_referral(self, c: Campaign, ev: Event) -> None:
        if c.reward_params.recipient != RECIPIENT_INVITER:
            log.warning("unsupported reward recipient", campaign=c.slug, recipient=c.reward_params.recipient)
            return
        await self._referrals.create(
            NewReward(
                campaign_id=c.id,
                user_id=ev.invited_by,
                invitee_id=ev.user_id,
                amount=c.reward_params.amount or 0,
            )
        )
