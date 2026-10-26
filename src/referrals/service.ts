// Rewards for invited friends: creation, statuses and payouts to the
// inviter's card through payout-gateway.
import type { Logger } from 'pino';

import { MAX_BATCH, type RewardCheck, type RewardVerdict, Verdict } from '../clients/antifraud.js';
import { NoPayoutMethodError, type Payout, type PayoutRequest } from '../clients/payout-gateway.js';
import { RewardStatus, statusName } from './status.js';

const PAYOUT_CURRENCY = 'RUB';
const PAYOUT_PURPOSE = 'referral_reward';

/**
 * How long a reward waits for the antifraud verdict. After that it goes to
 * payout anyway: fail open, honest users must not wait for antifraud longer
 * than a day.
 */
const ANTIFRAUD_VERDICT_TIMEOUT_MS = 24 * 60 * 60 * 1000;

/** A row of referral_rewards. userId is the inviter who gets the money, inviteeId is the invited friend. */
export interface Reward {
  id: number;
  campaignId: number;
  userId: number;
  inviteeId: number;
  amount: number;
  status: RewardStatus;
  statusChangedAt: Date;
  payoutId: string | null;
  createdAt: Date;
}

export interface NewReward {
  campaignId: number;
  userId: number;
  inviteeId: number;
  amount: number;
}

export interface RewardRepository {
  /**
   * Saves the reward unless the invitee already has one in the campaign;
   * resolves to false in that case.
   */
  insert(r: NewReward & { status: RewardStatus }): Promise<boolean>;
  byUser(userId: number, afterId: number, limit: number): Promise<Reward[]>;
  countByCampaign(campaignId: number): Promise<number>;
  byStatusAfter(status: RewardStatus, afterId: number, limit: number): Promise<Reward[]>;
  markPaid(id: number, payoutId: string): Promise<void>;
  /** Moves the reward from one status to another; a reward that is no longer in from is left as is. */
  setStatus(id: number, from: RewardStatus, to: RewardStatus): Promise<void>;
}

export interface PayoutClient {
  pay(req: PayoutRequest, signal?: AbortSignal): Promise<Payout>;
}

export interface AntifraudVerdicts {
  verdicts(items: RewardCheck[], signal?: AbortSignal): Promise<RewardVerdict[]>;
}

export class ReferralService {
  constructor(
    private readonly repo: RewardRepository,
    private readonly payouts: PayoutClient,
    private readonly antifraud: AntifraudVerdicts,
    private readonly log: Logger,
    private readonly now: () => Date = () => new Date(),
  ) {}

  /**
   * Saves a reward for the inviter. The reward waits for the antifraud
   * verdict (see checkAntifraud); once approved, it is paid by the
   * reward-payouts worker.
   */
  async create(nr: NewReward): Promise<void> {
    let created: boolean;
    try {
      created = await this.repo.insert({ ...nr, status: RewardStatus.WaitingAntifraudResult });
    } catch (err) {
      throw new Error(`insert reward: ${(err as Error).message}`, { cause: err });
    }
    if (!created) {
      this.log.info({ campaign_id: nr.campaignId, invitee_id: nr.inviteeId }, 'referral reward already exists');
      return;
    }
    this.log.info(
      { campaign_id: nr.campaignId, user_id: nr.userId, invitee_id: nr.inviteeId, amount: nr.amount },
      'referral reward created',
    );
  }

  byUser(userId: number, afterId: number, limit: number): Promise<Reward[]> {
    return this.repo.byUser(userId, afterId, limit);
  }

  countByCampaign(campaignId: number): Promise<number> {
    return this.repo.countByCampaign(campaignId);
  }

  /**
   * Pays out pending rewards, walking them by id in batches of batchSize.
   * A reward whose owner has no card stays pending and is tried again on
   * the next run.
   */
  async payPending(batchSize: number, signal: AbortSignal): Promise<void> {
    let lastId = 0;
    let paid = 0;
    let noMethod = 0;
    let failed = 0;

    for (;;) {
      let batch: Reward[];
      try {
        batch = await this.repo.byStatusAfter(RewardStatus.Pending, lastId, batchSize);
      } catch (err) {
        throw new Error(`select pending rewards: ${(err as Error).message}`, { cause: err });
      }
      if (batch.length === 0) {
        break;
      }

      for (const r of batch) {
        lastId = r.id;
        try {
          await this.payOut(r, signal);
          paid++;
        } catch (err) {
          if (err instanceof NoPayoutMethodError) {
            noMethod++;
            this.log.info({ reward_id: r.id, user_id: r.userId, reason: 'no_payout_method' }, 'reward not paid');
            continue;
          }
          signal.throwIfAborted();
          failed++;
          this.log.warn({ reward_id: r.id, user_id: r.userId, err }, 'reward payout failed');
        }
      }
    }

    this.log.info({ paid, no_payout_method: noMethod, failed }, 'reward payouts done');
  }

  /**
   * Asks antifraud for verdicts on rewards waiting for one, walking them by
   * id in batches of batchSize. Approved rewards become pending and are paid
   * by reward-payouts, rejected ones are closed. A reward still pending at
   * antifraud after ANTIFRAUD_VERDICT_TIMEOUT_MS is approved.
   */
  async checkAntifraud(batchSize: number, signal?: AbortSignal): Promise<void> {
    const limit = Math.min(batchSize, MAX_BATCH);
    const waitingStatus = RewardStatus.WaitingAntifraudResult;

    let lastId = 0;
    let approved = 0;
    let rejected = 0;
    let failedOpen = 0;
    let waiting = 0;

    for (;;) {
      let batch: Reward[];
      try {
        batch = await this.repo.byStatusAfter(waitingStatus, lastId, limit);
      } catch (err) {
        throw new Error(`select rewards waiting for antifraud: ${(err as Error).message}`, { cause: err });
      }
      if (batch.length === 0) {
        break;
      }
      const firstId = batch[0]!.id;
      lastId = batch[batch.length - 1]!.id;

      let verdicts: RewardVerdict[];
      try {
        verdicts = await this.antifraud.verdicts(batch.map(rewardCheck), signal);
      } catch (err) {
        throw new Error(`antifraud verdicts for rewards ${firstId}..${lastId}: ${(err as Error).message}`, {
          cause: err,
        });
      }

      const now = this.now().getTime();
      for (const [i, r] of batch.entries()) {
        const v = verdicts[i]!;
        if (v.reward_id !== r.id) {
          throw new Error(`antifraud verdict for reward ${v.reward_id}, expected ${r.id}`);
        }

        const waitedMs = now - r.statusChangedAt.getTime();
        if (v.verdict === Verdict.Approved) {
          await this.repo.setStatus(r.id, waitingStatus, RewardStatus.Pending);
          approved++;
        } else if (v.verdict === Verdict.Rejected) {
          await this.repo.setStatus(r.id, waitingStatus, RewardStatus.Rejected);
          rejected++;
          this.log.info({ reward_id: r.id, user_id: r.userId, reason: v.reason ?? '' }, 'reward rejected by antifraud');
        } else if (waitedMs >= ANTIFRAUD_VERDICT_TIMEOUT_MS) {
          await this.repo.setStatus(r.id, waitingStatus, RewardStatus.Pending);
          failedOpen++;
          this.log.warn(
            { reward_id: r.id, user_id: r.userId, waiting_min: Math.round(waitedMs / 60_000) },
            'no antifraud verdict in time, reward released to payout',
          );
        } else {
          waiting++;
        }
      }
    }

    this.log.info({ approved, rejected, failed_open: failedOpen, waiting }, 'antifraud check done');
  }

  private async payOut(r: Reward, signal: AbortSignal): Promise<void> {
    const p = await this.payouts.pay(
      {
        idempotency_key: String(r.id),
        user_id: r.userId,
        amount: r.amount,
        currency: PAYOUT_CURRENCY,
        purpose: PAYOUT_PURPOSE,
      },
      signal,
    );
    try {
      await this.repo.markPaid(r.id, p.payout_id);
    } catch (err) {
      throw new Error(`mark reward ${r.id} paid: ${(err as Error).message}`, { cause: err });
    }
  }
}

function rewardCheck(r: Reward): RewardCheck {
  return {
    reward_id: r.id,
    inviter_id: r.userId,
    invitee_id: r.inviteeId,
    amount: r.amount,
    currency: PAYOUT_CURRENCY,
    created_at: r.createdAt,
  };
}

export function rewardView(r: Reward) {
  return {
    id: r.id,
    invitee_id: r.inviteeId,
    amount: r.amount,
    status: statusName(r.status),
    status_changed_at: r.statusChangedAt,
    created_at: r.createdAt,
  };
}
