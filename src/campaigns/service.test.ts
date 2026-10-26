import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { pino } from 'pino';

import type { Discount, GrantRequest } from '../discounts/discount.js';
import { type Campaign, type CampaignEvent, type Conditions, RewardType, Trigger } from './campaign.js';
import {
  type CampaignRepository,
  CampaignService,
  type DiscountGranter,
  type OrderHistory,
  type ReferralRewards,
} from './service.js';

const testNow = new Date('2026-10-20T12:00:00Z');
const DAY_MS = 24 * 60 * 60 * 1000;

class FakeDiscounts implements DiscountGranter {
  readonly granted: GrantRequest[] = [];

  async grant(req: GrantRequest): Promise<Discount> {
    this.granted.push(req);
    return {
      id: this.granted.length,
      userId: req.userId,
      campaignId: req.campaignId,
      channel: null,
      paymentId: req.paymentId ?? null,
      percent: req.percent ?? null,
      amount: req.amount ?? null,
      maxAmount: req.maxAmount ?? null,
      expiresAt: req.expiresAt,
      usedAt: null,
      usedOrderId: null,
      createdBy: null,
      comment: null,
      createdAt: testNow,
    };
  }

  async countByCampaign(): Promise<number> {
    return 0;
  }

  async countByUser(): Promise<number> {
    return 0;
  }

  async countPromoUsedThisMonth(): Promise<number> {
    return 0;
  }
}

class FakeOrders implements OrderHistory {
  readonly counts = new Map<number, number>();
  error?: Error;
  calls = 0;

  async countOrders(userId: number): Promise<number> {
    this.calls++;
    if (this.error) {
      throw this.error;
    }
    return this.counts.get(userId) ?? 0;
  }
}

const noReferrals: ReferralRewards = { create: async () => {} };

function newService(...active: Campaign[]) {
  const repo = {
    activeByTrigger: async (trigger: Trigger) => active.filter((c) => c.trigger === trigger),
  } as Partial<CampaignRepository> as CampaignRepository;
  const discounts = new FakeDiscounts();
  const orders = new FakeOrders();
  const service = new CampaignService(repo, discounts, noReferrals, orders, 2, pino({ level: 'silent' }), () => testNow);
  return { service, discounts, orders };
}

function paymentCampaign(id: number, slug: string, conditions: Conditions): Campaign {
  const yesterday = new Date(testNow.getTime() - DAY_MS);
  return {
    id,
    slug,
    trigger: Trigger.PaymentSucceeded,
    promoCode: null,
    conditions,
    rewardType: RewardType.Discount,
    rewardParams: { percent: 20, ttl_days: 14 },
    startsAt: yesterday,
    endsAt: null,
    isActive: true,
    createdBy: null,
    createdAt: yesterday,
  };
}

function payment(userId: number, country = 'RU'): CampaignEvent {
  return {
    trigger: Trigger.PaymentSucceeded,
    userId,
    country,
    paymentId: 'pay-1',
    paymentMethod: 'card',
    skuIds: [100],
  };
}

const firstOrder = paymentCampaign(1, 'first_order', { countries: ['RU'], first_order: true });

describe('CampaignService.handle, first_order', () => {
  const cases: [name: string, orders: number, want: number][] = [
    ['first order', 1, 1],
    ['second order', 2, 0],
    ['regular customer', 37, 0],
    ['no orders in history', 0, 0],
  ];
  for (const [name, ordersInHistory, want] of cases) {
    it(name, async () => {
      const { service, discounts, orders } = newService(firstOrder);
      orders.counts.set(42, ordersInHistory);

      await service.handle(payment(42));

      assert.equal(discounts.granted.length, want);
      if (want === 1) {
        const g = discounts.granted[0]!;
        assert.equal(g.campaignId, 1);
        assert.equal(g.percent, 20);
        assert.deepEqual(g.expiresAt, new Date(testNow.getTime() + 14 * DAY_MS));
        assert.equal(g.notify, true);
      }
    });
  }

  it('does not ask the order history for another country', async () => {
    const { service, discounts, orders } = newService(firstOrder);
    orders.counts.set(42, 1);

    await service.handle(payment(42, 'KZ'));

    assert.equal(discounts.granted.length, 0);
    assert.equal(orders.calls, 0);
  });

  it('does not ask the order history for campaigns without the condition', async () => {
    const basket = paymentCampaign(2, 'basket', { countries: ['RU'], sku_ids: [100, 101], payment_methods: ['card'] });
    const { service, discounts, orders } = newService(basket);

    await service.handle(payment(42));

    assert.equal(discounts.granted.length, 1);
    assert.equal(orders.calls, 0);
  });

  it('fails the message when the order history fails', async () => {
    const { service, discounts, orders } = newService(paymentCampaign(1, 'first_order', { first_order: true }));
    orders.error = new Error('order-history: 503 unavailable');

    await assert.rejects(service.handle(payment(42)));
    assert.equal(discounts.granted.length, 0);
  });
});
