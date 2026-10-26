import type { Db } from '../db.js';
import type { NewReward, Reward, RewardRepository } from './service.js';
import { RewardStatus } from './status.js';

const REWARD_COLUMNS = `id, campaign_id, user_id, invitee_id, amount, status, status_changed_at,
  payout_id::text, created_at`;

interface RewardRow {
  id: number;
  campaign_id: number;
  user_id: number;
  invitee_id: number;
  amount: number;
  status: number;
  status_changed_at: Date;
  payout_id: string | null;
  created_at: Date;
}

export class PostgresRewardRepository implements RewardRepository {
  constructor(private readonly db: Db) {}

  async insert(r: NewReward & { status: RewardStatus }): Promise<boolean> {
    const { rowCount } = await this.db.query(
      `INSERT INTO referral_rewards (campaign_id, user_id, invitee_id, amount, status)
       VALUES ($1, $2, $3, $4, $5)
       ON CONFLICT (campaign_id, invitee_id) DO NOTHING`,
      [r.campaignId, r.userId, r.inviteeId, r.amount, r.status],
    );
    return rowCount === 1;
  }

  async byUser(userId: number, afterId: number, limit: number): Promise<Reward[]> {
    const { rows } = await this.db.query<RewardRow>(
      `SELECT ${REWARD_COLUMNS}
       FROM referral_rewards
       WHERE user_id = $1 AND ($2::bigint = 0 OR id < $2)
       ORDER BY id DESC
       LIMIT $3`,
      [userId, afterId, limit],
    );
    return rows.map(toReward);
  }

  async countByCampaign(campaignId: number): Promise<number> {
    const { rows } = await this.db.query<{ count: number }>(
      'SELECT count(*) FROM referral_rewards WHERE campaign_id = $1',
      [campaignId],
    );
    return rows[0]!.count;
  }

  async byStatusAfter(status: RewardStatus, afterId: number, limit: number): Promise<Reward[]> {
    const { rows } = await this.db.query<RewardRow>(
      `SELECT ${REWARD_COLUMNS}
       FROM referral_rewards
       WHERE status = $1 AND id > $2
       ORDER BY id
       LIMIT $3`,
      [status, afterId, limit],
    );
    return rows.map(toReward);
  }

  async markPaid(id: number, payoutId: string): Promise<void> {
    await this.db.query(
      `UPDATE referral_rewards
       SET status = $2, status_changed_at = now(), payout_id = $3::uuid
       WHERE id = $1 AND status = $4`,
      [id, RewardStatus.Paid, payoutId, RewardStatus.Pending],
    );
  }

  async setStatus(id: number, from: RewardStatus, to: RewardStatus): Promise<void> {
    await this.db.query(
      `UPDATE referral_rewards
       SET status = $2, status_changed_at = now()
       WHERE id = $1 AND status = $3`,
      [id, to, from],
    );
  }
}

function toReward(row: RewardRow): Reward {
  return {
    id: row.id,
    campaignId: row.campaign_id,
    userId: row.user_id,
    inviteeId: row.invitee_id,
    amount: row.amount,
    status: row.status,
    statusChangedAt: row.status_changed_at,
    payoutId: row.payout_id,
    createdAt: row.created_at,
  };
}
