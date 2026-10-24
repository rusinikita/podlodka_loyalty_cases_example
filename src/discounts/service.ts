import type { Logger } from 'pino';

import type { Push } from '../clients/notifications.js';
import type { CompensationConfig } from '../config/config.js';
import {
  CommentRequiredError,
  CompensationLimitError,
  type CompensationRequest,
  type Discount,
  DiscountUnavailableError,
  discountValue,
  type GrantRequest,
  matchOrder,
  type NewDiscount,
  type OrderConditions,
  type Quote,
  type QuoteRequest,
} from './discount.js';

const NEXT_ORDER_PUSH_TEMPLATE = 'loyalty_next_order_discount';
const DAY_MS = 24 * 60 * 60 * 1000;

/** An unused discount together with the order conditions of its campaign. */
export interface Redeemable {
  discount: Discount;
  conditions: OrderConditions;
}

export interface DiscountRepository {
  insert(d: NewDiscount): Promise<Discount>;
  active(userId: number, now: Date): Promise<Discount[]>;
  byUser(userId: number, afterId: number, limit: number): Promise<Discount[]>;
  redeemable(userId: number, at: Date): Promise<Redeemable[]>;
  markUsed(id: number, userId: number, orderId: number): Promise<boolean>;
  countByCampaign(campaignId: number): Promise<number>;
  countByUser(campaignId: number, userId: number): Promise<number>;
  countPromoUsedThisMonth(userId: number): Promise<number>;
}

export interface Notifier {
  push(p: Push, signal?: AbortSignal): Promise<void>;
}

export class DiscountService {
  constructor(
    private readonly repo: DiscountRepository,
    private readonly notifier: Notifier,
    private readonly compensation: CompensationConfig,
    private readonly log: Logger,
    private readonly now: () => Date = () => new Date(),
  ) {}

  /**
   * Saves a discount of a campaign. With notify the user also gets a push
   * about the discount for the next order.
   */
  async grant(req: GrantRequest, signal?: AbortSignal): Promise<Discount> {
    let d: Discount;
    try {
      d = await this.repo.insert({
        userId: req.userId,
        campaignId: req.campaignId,
        channel: req.channel || null,
        paymentId: req.paymentId || null,
        percent: req.percent || null,
        amount: req.amount || null,
        maxAmount: req.maxAmount || null,
        expiresAt: req.expiresAt,
        createdBy: null,
        comment: null,
      });
    } catch (err) {
      throw new Error(`insert discount: ${(err as Error).message}`, { cause: err });
    }

    if (req.notify) {
      try {
        await this.pushNextOrder(d, signal);
      } catch (err) {
        throw new Error(`push discount ${d.id}: ${(err as Error).message}`, { cause: err });
      }
    }

    this.log.info({ discount_id: d.id, user_id: d.userId, campaign_id: req.campaignId }, 'discount granted');
    return d;
  }

  private pushNextOrder(d: Discount, signal?: AbortSignal): Promise<void> {
    const params: Record<string, string> = { expires_at: d.expiresAt.toISOString().slice(0, 10) };
    if (d.percent !== null) {
      params.percent = String(d.percent);
    }
    if (d.amount !== null) {
      params.amount = String(d.amount);
    }
    return this.notifier.push({ user_id: d.userId, template: NEXT_ORDER_PUSH_TEMPLATE, params }, signal);
  }

  /**
   * Saves a discount given by an operator. It has no campaign; the operator
   * and the ticket number are kept in the row.
   */
  async grantCompensation(req: CompensationRequest): Promise<Discount> {
    if (
      req.percent < 1 ||
      req.percent > this.compensation.maxPercent ||
      req.ttlDays < 1 ||
      req.ttlDays > this.compensation.ttlDays
    ) {
      throw new CompensationLimitError();
    }
    if (!req.comment.trim()) {
      throw new CommentRequiredError();
    }

    let d: Discount;
    try {
      d = await this.repo.insert({
        userId: req.userId,
        campaignId: null,
        channel: null,
        paymentId: null,
        percent: req.percent,
        amount: null,
        maxAmount: req.maxAmount,
        expiresAt: new Date(this.now().getTime() + req.ttlDays * DAY_MS),
        createdBy: req.operator,
        comment: req.comment,
      });
    } catch (err) {
      throw new Error(`insert compensation: ${(err as Error).message}`, { cause: err });
    }
    this.log.info(
      { discount_id: d.id, user_id: d.userId, operator: req.operator, percent: req.percent },
      'compensation granted',
    );
    return d;
  }

  active(userId: number): Promise<Discount[]> {
    return this.repo.active(userId, this.now());
  }

  byUser(userId: number, afterId: number, limit: number): Promise<Discount[]> {
    return this.repo.byUser(userId, afterId, limit);
  }

  /**
   * Picks the user's discount for an order. Discounts do not stack: the
   * order gets the single largest one whose order conditions match.
   */
  async quote(req: QuoteRequest): Promise<Quote> {
    const candidates = await this.repo.redeemable(req.userId, new Date(req.orderedAt));

    let best: Quote = { discountId: null, amount: 0 };
    for (const c of candidates) {
      if (!matchOrder(c.conditions, req.orderAmount, req.orderedAt)) {
        continue;
      }
      const v = discountValue(c.discount, req.orderAmount);
      if (v > best.amount) {
        best = { discountId: c.discount.id, amount: v };
      }
    }
    return best;
  }

  async redeem(discountId: number, userId: number, orderId: number): Promise<void> {
    const ok = await this.repo.markUsed(discountId, userId, orderId);
    if (!ok) {
      throw new DiscountUnavailableError();
    }
  }

  countByCampaign(campaignId: number): Promise<number> {
    return this.repo.countByCampaign(campaignId);
  }

  countByUser(campaignId: number, userId: number): Promise<number> {
    return this.repo.countByUser(campaignId, userId);
  }

  countPromoUsedThisMonth(userId: number): Promise<number> {
    return this.repo.countPromoUsedThisMonth(userId);
  }
}
