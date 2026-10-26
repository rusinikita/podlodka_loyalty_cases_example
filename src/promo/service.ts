// Applies promo codes entered by users: finds the campaign of the code and
// passes it to campaigns.
import type { Logger } from 'pino';

import {
  type Campaign,
  type CampaignEvent,
  CampaignExpiredError,
  CampaignNotFoundError,
  IneligibleError,
  MonthlyLimitError,
  Trigger,
} from '../campaigns/campaign.js';
import type { Discount } from '../discounts/discount.js';

export const Channel = {
  Deeplink: 'deeplink',
  Manual: 'manual',
} as const;
export type Channel = (typeof Channel)[keyof typeof Channel];

export type PromoErrorCode =
  | 'promo_not_found'
  | 'promo_expired'
  | 'promo_already_used'
  | 'promo_activations_exhausted'
  | 'promo_monthly_limit'
  | 'promo_country_unavailable';

export class PromoError extends Error {
  constructor(readonly code: PromoErrorCode) {
    super(code.replaceAll('_', ' '));
  }
}

export interface PromoCampaigns {
  byPromoCode(code: string): Promise<Campaign>;
  applyPromoCode(c: Campaign, ev: CampaignEvent): Promise<Discount>;
}

export interface ApplyRequest {
  userId: number;
  country: string;
  cityId?: number;
  segment?: string;
  code: string;
  channel: Channel;
}

// Format characters (ZWSP, ZWNJ, ZWJ, word joiner, BOM, soft hyphen) and
// no-break spaces: not rendered or rendered as a blank inside a code.
const INVISIBLE = /[\p{Cf}\u00A0\u2007\u202F]/gu;

/**
 * Turns user input into the form promo codes are stored in. Codes are often
 * pasted from emails, messengers and notes, and the pasted text carries
 * invisible characters (zero-width spaces, soft hyphens, non-breaking spaces)
 * or full-width forms from some keyboards. NFKC folds compatibility forms
 * (full-width letters and digits, ligatures) into plain ones. Invisible
 * characters are dropped first, before NFKC turns no-break spaces into
 * ordinary ones.
 */
export function normalizeCode(raw: string): string {
  return raw.replace(INVISIBLE, '').normalize('NFKC').trim().toUpperCase();
}

export class PromoService {
  constructor(
    private readonly campaigns: PromoCampaigns,
    private readonly log: Logger,
  ) {}

  /**
   * Activates the promo code for the user: the user gets the discount of
   * the code's campaign for the next order.
   */
  async apply(req: ApplyRequest): Promise<Discount> {
    let c: Campaign;
    try {
      c = await this.campaigns.byPromoCode(normalizeCode(req.code));
    } catch (err) {
      if (err instanceof CampaignNotFoundError) {
        this.log.info({ input: req.code, user_id: req.userId, channel: req.channel }, 'promo not found');
        throw new PromoError('promo_not_found');
      }
      throw new Error(`get campaign by promo code: ${(err as Error).message}`, { cause: err });
    }

    let d: Discount;
    try {
      d = await this.campaigns.applyPromoCode(c, {
        trigger: Trigger.PromoCode,
        userId: req.userId,
        country: req.country,
        cityId: req.cityId,
        segment: req.segment,
        channel: req.channel,
      });
    } catch (err) {
      throw this.promoError(c, req, err);
    }

    this.log.info(
      { campaign_id: c.id, user_id: req.userId, channel: req.channel, discount_id: d.id },
      'promo applied',
    );
    return d;
  }

  private promoError(c: Campaign, req: ApplyRequest, err: unknown): Error {
    if (err instanceof IneligibleError) {
      switch (err.kind) {
        case 'not_eligible':
          return this.hide(c, req, err.reason);
        case 'already_used':
          return new PromoError('promo_already_used');
        case 'activations_exhausted':
          return new PromoError('promo_activations_exhausted');
        case 'wrong_country':
          return new PromoError('promo_country_unavailable');
      }
    }
    if (err instanceof CampaignExpiredError) {
      return new PromoError('promo_expired');
    }
    if (err instanceof MonthlyLimitError) {
      return new PromoError('promo_monthly_limit');
    }
    return new Error(`apply promo campaign ${c.id}: ${(err as Error).message}`, { cause: err });
  }

  /**
   * Makes a promo code the user is not eligible for look non-existent:
   * targeted and not yet launched campaigns must not leak to everyone else.
   */
  private hide(c: Campaign, req: ApplyRequest, reason: string): PromoError {
    this.log.info({ campaign_id: c.id, user_id: req.userId, channel: req.channel, reason }, 'promo hidden');
    return new PromoError('promo_not_found');
  }
}
