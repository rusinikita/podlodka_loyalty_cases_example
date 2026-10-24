"""Applies promo codes entered by users: finds the campaign of the code and
passes it to campaigns."""

from dataclasses import dataclass
from enum import StrEnum
from typing import Protocol

import structlog

from loyalty.campaigns.campaign import (
    ActivationsExhausted,
    AlreadyUsed,
    Campaign,
    CampaignExpired,
    CampaignNotFound,
    Event,
    Ineligible,
    MonthlyLimitReached,
    Trigger,
    WrongCountry,
)
from loyalty.discounts.discount import Discount

log = structlog.get_logger()


class Channel(StrEnum):
    DEEPLINK = "deeplink"
    MANUAL = "manual"


class PromoError(Exception):
    """A promo code error shown to the user; code is the error from the API spec."""

    code = "promo_error"

    def __init__(self) -> None:
        super().__init__(self.code.replace("_", " "))


class PromoNotFound(PromoError):
    code = "promo_not_found"


class PromoExpired(PromoError):
    code = "promo_expired"


class PromoAlreadyUsed(PromoError):
    code = "promo_already_used"


class PromoActivationsExhausted(PromoError):
    code = "promo_activations_exhausted"


class PromoMonthlyLimit(PromoError):
    code = "promo_monthly_limit"


class PromoCountryUnavailable(PromoError):
    code = "promo_country_unavailable"


class Campaigns(Protocol):
    async def by_promo_code(self, code: str) -> Campaign: ...
    async def apply_promo_code(self, c: Campaign, ev: Event) -> Discount: ...


@dataclass
class ApplyRequest:
    user_id: int
    country: str
    city_id: int
    segment: str
    code: str
    channel: Channel


def normalize_code(raw: str) -> str:
    return raw.strip().upper()


class PromoService:
    def __init__(self, campaigns: Campaigns) -> None:
        self._campaigns = campaigns

    async def apply(self, req: ApplyRequest) -> Discount:
        """Activates the promo code for the user: the user gets the discount of
        the code's campaign for the next order."""
        try:
            c = await self._campaigns.by_promo_code(normalize_code(req.code))
        except CampaignNotFound:
            log.info("promo not found", input=req.code, user_id=req.user_id, channel=req.channel)
            raise PromoNotFound() from None

        ev = Event(
            trigger=Trigger.PROMO_CODE,
            user_id=req.user_id,
            country=req.country,
            city_id=req.city_id,
            segment=req.segment,
            channel=req.channel,
        )
        try:
            d = await self._campaigns.apply_promo_code(c, ev)
        except WrongCountry as e:
            raise PromoCountryUnavailable() from e
        except AlreadyUsed as e:
            raise PromoAlreadyUsed() from e
        except ActivationsExhausted as e:
            raise PromoActivationsExhausted() from e
        except Ineligible as e:
            raise self._hide(c, req, e.reason) from e
        except CampaignExpired as e:
            raise PromoExpired() from e
        except MonthlyLimitReached as e:
            raise PromoMonthlyLimit() from e

        log.info("promo applied", campaign_id=c.id, user_id=req.user_id, channel=req.channel, discount_id=d.id)
        return d

    def _hide(self, c: Campaign, req: ApplyRequest, reason: str) -> PromoError:
        """Makes a promo code the user is not eligible for look non-existent:
        targeted and not yet launched campaigns must not leak to everyone else."""
        log.info("promo hidden", campaign_id=c.id, user_id=req.user_id, channel=req.channel, reason=reason)
        return PromoNotFound()
