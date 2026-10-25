import type { Logger } from 'pino';

import type { Discount, GrantRequest } from '../discounts/discount.js';
import type { NewReward } from '../referrals/service.js';
import type { CampaignFields, CampaignInput, SearchFilter } from './admin.js';
import { applyInput, validate } from './admin.js';
import {
  type Campaign,
  type CampaignEvent,
  CampaignExpiredError,
  IneligibleError,
  MonthlyLimitError,
  RECIPIENT_INVITER,
  RewardType,
  SEGMENT_ALL,
  Trigger,
} from './campaign.js';

export interface CampaignRepository {
  activeByTrigger(trigger: Trigger, now: Date): Promise<Campaign[]>;
  byPromoCode(code: string): Promise<Campaign>;
  byId(id: number): Promise<Campaign>;
  search(filter: SearchFilter): Promise<Campaign[]>;
  insert(c: CampaignFields): Promise<Campaign>;
  update(c: Campaign): Promise<Campaign>;
}

export interface DiscountGranter {
  grant(req: GrantRequest, signal?: AbortSignal): Promise<Discount>;
  countByCampaign(campaignId: number): Promise<number>;
  countByUser(campaignId: number, userId: number): Promise<number>;
  countPromoUsedThisMonth(userId: number): Promise<number>;
}

export interface ReferralRewards {
  create(reward: NewReward): Promise<void>;
}

/** Counts the user's paid and completed orders across all channels and payment methods. */
export interface OrderHistory {
  countOrders(userId: number, signal?: AbortSignal): Promise<number>;
}

const DAY_MS = 24 * 60 * 60 * 1000;

export class CampaignService {
  constructor(
    private readonly repo: CampaignRepository,
    private readonly discounts: DiscountGranter,
    private readonly referrals: ReferralRewards,
    private readonly orders: OrderHistory,
    private readonly maxPromoPerMonth: number,
    private readonly log: Logger,
    private readonly now: () => Date = () => new Date(),
  ) {}

  /**
   * Processes a payment or a registration: every active campaign of the
   * trigger whose grant conditions match the event grants its reward.
   */
  async handle(ev: CampaignEvent, signal?: AbortSignal): Promise<void> {
    const active = await this.repo.activeByTrigger(ev.trigger, this.now());

    const matched: Campaign[] = [];
    for (const c of active) {
      try {
        await this.checkGrant(c, ev, signal);
      } catch (err) {
        if (err instanceof IneligibleError) {
          this.log.debug({ campaign: c.slug, user_id: ev.userId, reason: err.reason }, 'campaign skipped');
          continue;
        }
        throw new Error(`check campaign ${c.slug}: ${(err as Error).message}`, { cause: err });
      }
      matched.push(c);
    }

    for (const c of matched) {
      try {
        await this.grant(c, ev, signal);
      } catch (err) {
        throw new Error(`grant campaign ${c.slug}: ${(err as Error).message}`, { cause: err });
      }
    }
  }

  byPromoCode(code: string): Promise<Campaign> {
    return this.repo.byPromoCode(code);
  }

  /** Grants the discount of a promo code campaign to the user who entered the code. */
  async applyPromoCode(c: Campaign, ev: CampaignEvent): Promise<Discount> {
    const now = this.now();
    if (!c.isActive || now < c.startsAt) {
      throw new IneligibleError('inactive');
    }
    if (c.endsAt && now >= c.endsAt) {
      throw new CampaignExpiredError();
    }
    await this.checkGrant(c, ev);

    const used = await this.discounts.countPromoUsedThisMonth(ev.userId);
    if (used >= this.maxPromoPerMonth) {
      throw new MonthlyLimitError();
    }

    return this.grantDiscount(c, ev);
  }

  get(id: number): Promise<Campaign> {
    return this.repo.byId(id);
  }

  search(filter: SearchFilter): Promise<Campaign[]> {
    return this.repo.search(filter);
  }

  async create(input: CampaignInput, operator: string): Promise<Campaign> {
    const fields = validate(
      applyInput(
        {
          slug: '',
          trigger: '',
          promoCode: null,
          conditions: {},
          rewardType: '',
          rewardParams: {},
          startsAt: null,
          endsAt: null,
          isActive: true,
          createdBy: operator,
        },
        input,
      ),
    );
    const created = await this.repo.insert(fields);
    this.log.info({ campaign_id: created.id, slug: created.slug, operator }, 'campaign created');
    return created;
  }

  async update(id: number, input: CampaignInput, operator: string): Promise<Campaign> {
    const current = await this.repo.byId(id);
    const fields = validate(applyInput(current, input));
    const updated = await this.repo.update({ ...fields, id, createdAt: current.createdAt });
    this.log.info({ campaign_id: id, operator }, 'campaign updated');
    return updated;
  }

  /** Throws IneligibleError if a grant condition of c does not match the event. */
  private async checkGrant(c: Campaign, ev: CampaignEvent, signal?: AbortSignal): Promise<void> {
    const cond = c.conditions;

    if (cond.countries?.length && !cond.countries.includes(ev.country)) {
      throw new IneligibleError('country', 'wrong_country');
    }
    if (cond.segment && cond.segment !== SEGMENT_ALL && cond.segment !== ev.segment) {
      throw new IneligibleError('segment');
    }
    if (cond.city_ids?.length && !cond.city_ids.includes(ev.cityId ?? 0)) {
      throw new IneligibleError('city');
    }
    if (cond.sku_ids?.length && !(ev.skuIds ?? []).some((id) => cond.sku_ids!.includes(id))) {
      throw new IneligibleError('sku');
    }
    if (cond.payment_methods?.length && !cond.payment_methods.includes(ev.paymentMethod ?? '')) {
      throw new IneligibleError('payment_method');
    }
    if (cond.invited && !ev.invitedBy) {
      throw new IneligibleError('not_invited');
    }
    if (cond.first_order && !(await this.isFirstOrder(ev.userId, signal))) {
      throw new IneligibleError('not_first_order');
    }

    if (cond.per_user_limit) {
      const n = await this.discounts.countByUser(c.id, ev.userId);
      if (n >= cond.per_user_limit) {
        throw new IneligibleError('per_user_limit', 'already_used');
      }
    }
    if (cond.max_activations) {
      const n = await this.discounts.countByCampaign(c.id);
      if (n >= cond.max_activations) {
        throw new IneligibleError('max_activations', 'activations_exhausted');
      }
    }
  }

  /**
   * Whether the payment is for the user's first order: the order history,
   * which already includes the paid order, has exactly one.
   */
  private async isFirstOrder(userId: number, signal?: AbortSignal): Promise<boolean> {
    let n: number;
    try {
      n = await this.orders.countOrders(userId, signal);
    } catch (err) {
      throw new Error(`count user orders: ${(err as Error).message}`, { cause: err });
    }
    return n === 1;
  }

  private async grant(c: Campaign, ev: CampaignEvent, signal?: AbortSignal): Promise<void> {
    switch (c.rewardType) {
      case RewardType.Discount:
        await this.grantDiscount(c, ev, signal);
        return;
      case RewardType.Referral:
        await this.grantReferral(c, ev);
        return;
      default:
        this.log.warn({ campaign: c.slug, reward_type: c.rewardType }, 'unknown reward type');
    }
  }

  private grantDiscount(c: Campaign, ev: CampaignEvent, signal?: AbortSignal): Promise<Discount> {
    const p = c.rewardParams;

    let expiresAt: Date;
    if (p.ttl_days) {
      expiresAt = new Date(this.now().getTime() + p.ttl_days * DAY_MS);
    } else if (c.endsAt) {
      expiresAt = c.endsAt;
    } else {
      throw new Error(`campaign ${c.slug}: neither ttl_days nor ends_at is set`);
    }

    return this.discounts.grant(
      {
        userId: ev.userId,
        campaignId: c.id,
        channel: ev.channel,
        paymentId: ev.paymentId,
        percent: p.percent,
        amount: p.amount,
        maxAmount: p.max_amount,
        expiresAt,
        notify: c.trigger === Trigger.PaymentSucceeded,
      },
      signal,
    );
  }

  private async grantReferral(c: Campaign, ev: CampaignEvent): Promise<void> {
    if (c.rewardParams.recipient !== RECIPIENT_INVITER) {
      this.log.warn({ campaign: c.slug, recipient: c.rewardParams.recipient }, 'unsupported reward recipient');
      return;
    }
    await this.referrals.create({
      campaignId: c.id,
      userId: ev.invitedBy ?? 0,
      inviteeId: ev.userId,
      amount: c.rewardParams.amount ?? 0,
    });
  }
}
