import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { pino } from 'pino';

import { type RewardCheck, type RewardVerdict, Verdict } from '../clients/antifraud.js';
import type { Payout } from '../clients/payout-gateway.js';
import { type AntifraudVerdicts, type NewReward, ReferralService, type Reward, type RewardRepository } from './service.js';
import { RewardStatus } from './status.js';

const testNow = new Date('2026-10-20T12:00:00Z');
const HOUR_MS = 60 * 60 * 1000;

class FakeRepo implements RewardRepository {
  readonly rewards = new Map<number, Reward>();
  readonly inserted: RewardStatus[] = [];

  constructor(rewards: Reward[] = []) {
    for (const r of rewards) {
      this.rewards.set(r.id, r);
    }
  }

  async insert(r: NewReward & { status: RewardStatus }): Promise<boolean> {
    this.inserted.push(r.status);
    return true;
  }

  async byUser(): Promise<Reward[]> {
    return [];
  }

  async countByCampaign(): Promise<number> {
    return 0;
  }

  async byStatusAfter(status: RewardStatus, afterId: number, limit: number): Promise<Reward[]> {
    return [...this.rewards.values()]
      .filter((r) => r.status === status && r.id > afterId)
      .sort((a, b) => a.id - b.id)
      .slice(0, limit);
  }

  async markPaid(): Promise<void> {}

  async setStatus(id: number, from: RewardStatus, to: RewardStatus): Promise<void> {
    const r = this.rewards.get(id);
    if (r && r.status === from) {
      this.rewards.set(id, { ...r, status: to, statusChangedAt: testNow });
    }
  }
}

class FakeAntifraud implements AntifraudVerdicts {
  readonly verdictsById = new Map<number, Verdict>();
  readonly calls: number[][] = [];

  async verdicts(items: RewardCheck[]): Promise<RewardVerdict[]> {
    this.calls.push(items.map((it) => it.reward_id));
    return items.map((it) => ({ reward_id: it.reward_id, verdict: this.verdictsById.get(it.reward_id) ?? Verdict.Pending }));
  }
}

const noPayouts = {
  pay: async (): Promise<Payout> => {
    throw new Error('no payouts expected');
  },
};

function newService(rewards: Reward[] = []) {
  const repo = new FakeRepo(rewards);
  const antifraud = new FakeAntifraud();
  const service = new ReferralService(repo, noPayouts, antifraud, pino({ level: 'silent' }), () => testNow);
  return { service, repo, antifraud };
}

function waiting(id: number, sinceMs: number): Reward {
  const at = new Date(testNow.getTime() - sinceMs);
  return {
    id,
    campaignId: 17,
    userId: 1000 + id,
    inviteeId: 2000 + id,
    amount: 500,
    status: RewardStatus.WaitingAntifraudResult,
    statusChangedAt: at,
    payoutId: null,
    createdAt: at,
  };
}

describe('ReferralService.checkAntifraud', () => {
  const cases: [name: string, reward: Reward, verdict: Verdict, want: RewardStatus][] = [
    ['approved', waiting(1, HOUR_MS), Verdict.Approved, RewardStatus.Pending],
    ['rejected', waiting(2, HOUR_MS), Verdict.Rejected, RewardStatus.Rejected],
    ['pending, fresh', waiting(3, 6 * HOUR_MS), Verdict.Pending, RewardStatus.WaitingAntifraudResult],
    ['pending, almost a day', waiting(4, 24 * HOUR_MS - 60_000), Verdict.Pending, RewardStatus.WaitingAntifraudResult],
    ['pending, a day', waiting(5, 24 * HOUR_MS), Verdict.Pending, RewardStatus.Pending],
    ['pending, two days', waiting(6, 48 * HOUR_MS), Verdict.Pending, RewardStatus.Pending],
    ['rejected after a day', waiting(7, 30 * HOUR_MS), Verdict.Rejected, RewardStatus.Rejected],
  ];
  for (const [name, reward, verdict, want] of cases) {
    it(name, async () => {
      const { service, repo, antifraud } = newService([reward]);
      antifraud.verdictsById.set(reward.id, verdict);

      await service.checkAntifraud(500);

      assert.equal(repo.rewards.get(reward.id)!.status, want);
    });
  }

  it('walks rewards in batches by id', async () => {
    const rewards = [1, 2, 3, 4, 5, 6, 7].map((id) => waiting(id, HOUR_MS));
    const paid = { ...waiting(8, HOUR_MS), status: RewardStatus.Paid };
    const { service, repo, antifraud } = newService([...rewards, paid]);

    await service.checkAntifraud(3);

    assert.deepEqual(antifraud.calls, [[1, 2, 3], [4, 5, 6], [7]]);
    assert.equal(repo.rewards.get(8)!.status, RewardStatus.Paid);
  });
});

describe('ReferralService.create', () => {
  it('creates the reward waiting for antifraud', async () => {
    const { service, repo } = newService();

    await service.create({ campaignId: 17, userId: 1, inviteeId: 2, amount: 500 });

    assert.deepEqual(repo.inserted, [RewardStatus.WaitingAntifraudResult]);
  });
});
