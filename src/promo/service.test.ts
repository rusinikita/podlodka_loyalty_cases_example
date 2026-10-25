import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { pino } from 'pino';

import { type Campaign, type CampaignEvent, CampaignNotFoundError, RewardType, Trigger } from '../campaigns/campaign.js';
import type { Discount } from '../discounts/discount.js';
import { Channel, normalizeCode, type PromoCampaigns, PromoError, PromoService } from './service.js';

describe('normalizeCode', () => {
  const cases: [name: string, input: string, want: string][] = [
    ['as stored', 'WELCOME300', 'WELCOME300'],
    ['lower case', 'welcome300', 'WELCOME300'],
    ['mixed case', 'WeLcOmE300', 'WELCOME300'],
    ['surrounding spaces', '  WELCOME300 \n', 'WELCOME300'],
    ['tab', '\tWELCOME300', 'WELCOME300'],
    ['zero-width space inside', 'WELC\u200bOME300', 'WELCOME300'],
    ['zero-width joiner and non-joiner', '\u200dWELCOME\u200c300', 'WELCOME300'],
    ['word joiner', 'WELCOME\u2060300', 'WELCOME300'],
    ['byte order mark', '\ufeffWELCOME300', 'WELCOME300'],
    ['soft hyphen', 'WEL\u00adCOME300', 'WELCOME300'],
    ['no-break space inside', 'WELCOME\u00a0300', 'WELCOME300'],
    ['narrow no-break space at the end', 'WELCOME300\u202f', 'WELCOME300'],
    ['full-width letters and digits', 'ＷＥＬＣＯＭＥ３００', 'WELCOME300'],
    ['full-width digits only', 'AUTUMN１５', 'AUTUMN15'],
    ['full-width lower case', 'ａｕｔｕｍｎ15', 'AUTUMN15'],
    ['code with a dash', 'pulse-93kd', 'PULSE-93KD'],
    ['pasted from a message', ' \u200bautumn１５\u00a0', 'AUTUMN15'],
    ['empty', '', ''],
    ['only invisible characters', '\u200b\u00ad\ufeff', ''],
  ];
  for (const [name, input, want] of cases) {
    it(name, () => {
      assert.equal(normalizeCode(input), want);
    });
  }
});

class FakeCampaigns implements PromoCampaigns {
  readonly applied: string[] = [];

  constructor(private readonly byCode: Map<string, Campaign>) {}

  async byPromoCode(code: string): Promise<Campaign> {
    const c = this.byCode.get(code);
    if (!c) {
      throw new CampaignNotFoundError();
    }
    return c;
  }

  async applyPromoCode(c: Campaign, ev: CampaignEvent): Promise<Discount> {
    this.applied.push(c.promoCode ?? '');
    const now = new Date();
    return {
      id: this.applied.length,
      userId: ev.userId,
      campaignId: c.id,
      channel: ev.channel ?? null,
      paymentId: null,
      percent: null,
      amount: 300,
      maxAmount: null,
      expiresAt: new Date(now.getTime() + 14 * 24 * 3600 * 1000),
      usedAt: null,
      usedOrderId: null,
      createdBy: null,
      comment: null,
      createdAt: now,
    };
  }
}

function promoCampaign(code: string): Campaign {
  const yesterday = new Date(Date.now() - 24 * 3600 * 1000);
  return {
    id: 1,
    slug: 'welcome_300',
    trigger: Trigger.PromoCode,
    promoCode: code,
    conditions: {},
    rewardType: RewardType.Discount,
    rewardParams: { amount: 300 },
    startsAt: yesterday,
    endsAt: null,
    isActive: true,
    createdBy: null,
    createdAt: yesterday,
  };
}

describe('PromoService.apply', () => {
  it('finds the campaign for normalized input', async () => {
    const campaigns = new FakeCampaigns(new Map([['WELCOME300', promoCampaign('WELCOME300')]]));
    const promo = new PromoService(campaigns, pino({ level: 'silent' }));
    const request = (code: string) => ({ userId: 7, country: 'RU', code, channel: Channel.Manual });

    const inputs = ['WELCOME300', 'welcome300', 'WELC\u200bOME300', 'ＷＥＬＣＯＭＥ３００', 'WELCOME\u00a0300 '];
    for (const input of inputs) {
      await promo.apply(request(input));
    }
    assert.equal(campaigns.applied.length, inputs.length);

    await assert.rejects(promo.apply(request('WELCOME30')), (err: unknown) => {
      return err instanceof PromoError && err.code === 'promo_not_found';
    });
  });
});
