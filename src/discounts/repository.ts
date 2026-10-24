import type { Db } from '../db.js';
import type { Discount, NewDiscount, OrderConditions } from './discount.js';
import type { DiscountRepository, Redeemable } from './service.js';

const DISCOUNT_COLUMNS = `d.id, d.user_id, d.campaign_id, d.channel, d.payment_id, d.percent, d.amount,
  d.max_amount, d.expires_at, d.used_at, d.used_order_id, d.created_by, d.comment, d.created_at`;

interface DiscountRow {
  id: number;
  user_id: number;
  campaign_id: number | null;
  channel: string | null;
  payment_id: string | null;
  percent: number | null;
  amount: number | null;
  max_amount: number | null;
  expires_at: Date;
  used_at: Date | null;
  used_order_id: number | null;
  created_by: string | null;
  comment: string | null;
  created_at: Date;
}

export class PostgresDiscountRepository implements DiscountRepository {
  constructor(private readonly db: Db) {}

  async insert(d: NewDiscount): Promise<Discount> {
    const { rows } = await this.db.query<DiscountRow>(
      `INSERT INTO user_discounts AS d
         (user_id, campaign_id, channel, payment_id, percent, amount, max_amount, expires_at, created_by, comment)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
       RETURNING ${DISCOUNT_COLUMNS}`,
      [
        d.userId,
        d.campaignId,
        d.channel,
        d.paymentId,
        d.percent,
        d.amount,
        d.maxAmount,
        d.expiresAt,
        d.createdBy,
        d.comment,
      ],
    );
    return toDiscount(rows[0]!);
  }

  async active(userId: number, now: Date): Promise<Discount[]> {
    const { rows } = await this.db.query<DiscountRow>(
      `SELECT ${DISCOUNT_COLUMNS}
       FROM user_discounts d
       WHERE d.user_id = $1 AND d.used_at IS NULL AND d.expires_at > $2
       ORDER BY d.expires_at`,
      [userId, now],
    );
    return rows.map(toDiscount);
  }

  async byUser(userId: number, afterId: number, limit: number): Promise<Discount[]> {
    const { rows } = await this.db.query<DiscountRow>(
      `SELECT ${DISCOUNT_COLUMNS}
       FROM user_discounts d
       WHERE d.user_id = $1 AND ($2::bigint = 0 OR d.id < $2)
       ORDER BY d.id DESC
       LIMIT $3`,
      [userId, afterId, limit],
    );
    return rows.map(toDiscount);
  }

  async redeemable(userId: number, at: Date): Promise<Redeemable[]> {
    const { rows } = await this.db.query<DiscountRow & { conditions: OrderConditions }>(
      `SELECT ${DISCOUNT_COLUMNS}, coalesce(c.conditions, '{}') AS conditions
       FROM user_discounts d
       LEFT JOIN campaigns c ON c.id = d.campaign_id
       WHERE d.user_id = $1 AND d.used_at IS NULL AND d.expires_at > $2`,
      [userId, at],
    );
    return rows.map((row) => ({ discount: toDiscount(row), conditions: row.conditions }));
  }

  async markUsed(id: number, userId: number, orderId: number): Promise<boolean> {
    const { rowCount } = await this.db.query(
      `UPDATE user_discounts
       SET used_at = now(), used_order_id = $3
       WHERE id = $1 AND user_id = $2 AND used_at IS NULL AND expires_at > now()`,
      [id, userId, orderId],
    );
    return rowCount === 1;
  }

  async countByCampaign(campaignId: number): Promise<number> {
    const { rows } = await this.db.query<{ count: number }>(
      'SELECT count(*) FROM user_discounts WHERE campaign_id = $1',
      [campaignId],
    );
    return rows[0]!.count;
  }

  async countByUser(campaignId: number, userId: number): Promise<number> {
    const { rows } = await this.db.query<{ count: number }>(
      'SELECT count(*) FROM user_discounts WHERE campaign_id = $1 AND user_id = $2',
      [campaignId, userId],
    );
    return rows[0]!.count;
  }

  async countPromoUsedThisMonth(userId: number): Promise<number> {
    const { rows } = await this.db.query<{ count: number }>(
      `SELECT count(*)
       FROM user_discounts d
       JOIN campaigns c ON c.id = d.campaign_id
       WHERE d.user_id = $1
         AND c.trigger = 'promo_code'
         AND d.used_at >= date_trunc('month', now())`,
      [userId],
    );
    return rows[0]!.count;
  }
}

function toDiscount(row: DiscountRow): Discount {
  return {
    id: row.id,
    userId: row.user_id,
    campaignId: row.campaign_id,
    channel: row.channel,
    paymentId: row.payment_id,
    percent: row.percent,
    amount: row.amount,
    maxAmount: row.max_amount,
    expiresAt: row.expires_at,
    usedAt: row.used_at,
    usedOrderId: row.used_order_id,
    createdBy: row.created_by,
    comment: row.comment,
    createdAt: row.created_at,
  };
}
