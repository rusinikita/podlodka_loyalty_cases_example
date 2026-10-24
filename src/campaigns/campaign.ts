// Loyalty campaigns: on a trigger the service picks the active campaigns,
// checks their grant conditions and grants the reward.

export const Trigger = {
  PromoCode: 'promo_code',
  PaymentSucceeded: 'payment_succeeded',
  UserRegistered: 'user_registered',
} as const;
export type Trigger = (typeof Trigger)[keyof typeof Trigger];

export const RewardType = {
  Discount: 'discount',
  Referral: 'referral_reward',
} as const;
export type RewardType = (typeof RewardType)[keyof typeof RewardType];

export const SEGMENT_ALL = 'all';
export const RECIPIENT_INVITER = 'inviter';

export interface Campaign {
  id: number;
  slug: string;
  trigger: Trigger;
  promoCode: string | null;
  conditions: Conditions;
  rewardType: RewardType;
  rewardParams: RewardParams;
  startsAt: Date;
  endsAt: Date | null;
  isActive: boolean;
  createdBy: string | null;
  createdAt: Date;
}

/**
 * Conditions of a campaign, stored as jsonb. Grant conditions are checked
 * by CampaignService when the trigger fires; order conditions (time_from,
 * time_to, min_order_amount) are checked by discounts when the discount
 * is used.
 */
export interface Conditions {
  countries?: string[];
  segment?: string;
  city_ids?: number[];
  per_user_limit?: number;
  max_activations?: number;
  sku_ids?: number[];
  payment_methods?: string[];
  invited?: boolean;
  time_from?: string;
  time_to?: string;
  min_order_amount?: number;
}

/**
 * Reward parameters, stored as jsonb. A discount has percent with an
 * optional max_amount cap, or a fixed amount; ttl_days, or until the end of
 * the campaign if not set. A referral reward has amount and recipient.
 */
export interface RewardParams {
  percent?: number;
  amount?: number;
  max_amount?: number;
  ttl_days?: number;
  recipient?: string;
}

/**
 * What fired the trigger: a promo code entered in the app, a payment or
 * a registration. Fields that the trigger does not carry are left out.
 */
export interface CampaignEvent {
  trigger: Trigger;
  userId: number;
  country: string;
  cityId?: number;
  segment?: string;

  // promo_code
  channel?: string;

  // payment_succeeded
  paymentId?: string;
  paymentMethod?: string;
  skuIds?: number[];

  // user_registered; not set if the user was not invited
  invitedBy?: number;
}

export class CampaignNotFoundError extends Error {
  constructor() {
    super('campaign not found');
  }
}

export class CampaignConflictError extends Error {
  constructor() {
    super('slug or promo code already exists');
  }
}

export class InvalidCampaignError extends Error {
  constructor(message: string) {
    super(`invalid campaign: ${message}`);
  }
}

export class CampaignExpiredError extends Error {
  constructor() {
    super('campaign expired');
  }
}

export class MonthlyLimitError extends Error {
  constructor() {
    super('monthly promo code limit reached');
  }
}

/**
 * Which kind of grant condition did not match: the user's country, the
 * per-user limit, the campaign's activation limit, or any other condition.
 */
export type IneligibleKind = 'not_eligible' | 'wrong_country' | 'already_used' | 'activations_exhausted';

const ineligibleMessages: Record<IneligibleKind, string> = {
  not_eligible: 'not eligible',
  wrong_country: "campaign is not available in the user's country",
  already_used: 'per-user limit reached',
  activations_exhausted: 'campaign activations exhausted',
};

/** A grant condition of the campaign does not match the event. */
export class IneligibleError extends Error {
  constructor(
    readonly reason: string,
    readonly kind: IneligibleKind = 'not_eligible',
  ) {
    super(`${ineligibleMessages[kind]}: ${reason}`);
  }
}

export function campaignView(c: Campaign) {
  return {
    id: c.id,
    slug: c.slug,
    trigger: c.trigger,
    promo_code: c.promoCode,
    conditions: c.conditions,
    reward_type: c.rewardType,
    reward_params: c.rewardParams,
    starts_at: c.startsAt,
    ends_at: c.endsAt,
    is_active: c.isActive,
    ...(c.createdBy ? { created_by: c.createdBy } : {}),
    created_at: c.createdAt,
  };
}
