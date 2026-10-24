import { type Db, isUniqueViolation } from '../db.js';
import type { CampaignFields, SearchFilter } from './admin.js';
import type { CampaignRepository } from './service.js';
import { type Campaign, CampaignConflictError, CampaignNotFoundError, type Trigger } from './campaign.js';

const CAMPAIGN_COLUMNS = `id, slug, trigger, promo_code, conditions, reward_type, reward_params,
  starts_at, ends_at, is_active, created_by, created_at`;

interface CampaignRow {
  id: number;
  slug: string;
  trigger: Campaign['trigger'];
  promo_code: string | null;
  conditions: Campaign['conditions'];
  reward_type: Campaign['rewardType'];
  reward_params: Campaign['rewardParams'];
  starts_at: Date;
  ends_at: Date | null;
  is_active: boolean;
  created_by: string | null;
  created_at: Date;
}

export class PostgresCampaignRepository implements CampaignRepository {
  constructor(private readonly db: Db) {}

  async activeByTrigger(trigger: Trigger, now: Date): Promise<Campaign[]> {
    const { rows } = await this.db.query<CampaignRow>(
      `SELECT ${CAMPAIGN_COLUMNS}
       FROM campaigns
       WHERE trigger = $1
         AND is_active
         AND starts_at <= $2
         AND (ends_at IS NULL OR ends_at > $2)
       ORDER BY id`,
      [trigger, now],
    );
    return rows.map(toCampaign);
  }

  async byPromoCode(code: string): Promise<Campaign> {
    const { rows } = await this.db.query<CampaignRow>(
      `SELECT ${CAMPAIGN_COLUMNS}
       FROM campaigns
       WHERE promo_code = $1`,
      [code],
    );
    return one(rows);
  }

  async byId(id: number): Promise<Campaign> {
    const { rows } = await this.db.query<CampaignRow>(`SELECT ${CAMPAIGN_COLUMNS} FROM campaigns WHERE id = $1`, [id]);
    return one(rows);
  }

  async search(f: SearchFilter): Promise<Campaign[]> {
    const { rows } = await this.db.query<CampaignRow>(
      `SELECT ${CAMPAIGN_COLUMNS}
       FROM campaigns
       WHERE ($1 = '' OR slug ILIKE '%' || $1 || '%' OR promo_code ILIKE '%' || $1 || '%')
         AND ($2 = '' OR trigger = $2)
         AND ($3::boolean IS NULL OR is_active = $3)
         AND id > $4
       ORDER BY id
       LIMIT $5`,
      [f.query, f.trigger, f.active ?? null, f.afterId, f.limit],
    );
    return rows.map(toCampaign);
  }

  async insert(c: CampaignFields): Promise<Campaign> {
    try {
      const { rows } = await this.db.query<CampaignRow>(
        `INSERT INTO campaigns
           (slug, trigger, promo_code, conditions, reward_type, reward_params, starts_at, ends_at, is_active, created_by)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
         RETURNING ${CAMPAIGN_COLUMNS}`,
        [
          c.slug,
          c.trigger,
          c.promoCode,
          JSON.stringify(c.conditions),
          c.rewardType,
          JSON.stringify(c.rewardParams),
          c.startsAt,
          c.endsAt,
          c.isActive,
          c.createdBy,
        ],
      );
      return one(rows);
    } catch (err) {
      if (isUniqueViolation(err)) {
        throw new CampaignConflictError();
      }
      throw err;
    }
  }

  async update(c: Campaign): Promise<Campaign> {
    try {
      const { rows } = await this.db.query<CampaignRow>(
        `UPDATE campaigns
         SET slug = $2, trigger = $3, promo_code = $4, conditions = $5, reward_type = $6,
             reward_params = $7, starts_at = $8, ends_at = $9, is_active = $10
         WHERE id = $1
         RETURNING ${CAMPAIGN_COLUMNS}`,
        [
          c.id,
          c.slug,
          c.trigger,
          c.promoCode,
          JSON.stringify(c.conditions),
          c.rewardType,
          JSON.stringify(c.rewardParams),
          c.startsAt,
          c.endsAt,
          c.isActive,
        ],
      );
      return one(rows);
    } catch (err) {
      if (isUniqueViolation(err)) {
        throw new CampaignConflictError();
      }
      throw err;
    }
  }
}

function one(rows: CampaignRow[]): Campaign {
  const row = rows[0];
  if (!row) {
    throw new CampaignNotFoundError();
  }
  return toCampaign(row);
}

function toCampaign(row: CampaignRow): Campaign {
  return {
    id: row.id,
    slug: row.slug,
    trigger: row.trigger,
    promoCode: row.promo_code,
    conditions: row.conditions,
    rewardType: row.reward_type,
    rewardParams: row.reward_params,
    startsAt: row.starts_at,
    endsAt: row.ends_at,
    isActive: row.is_active,
    createdBy: row.created_by,
    createdAt: row.created_at,
  };
}
