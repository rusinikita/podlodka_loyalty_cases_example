import {
  type Campaign,
  type Conditions,
  InvalidCampaignError,
  type RewardParams,
  RewardType,
  Trigger,
} from './campaign.js';

export interface SearchFilter {
  query: string;
  trigger: string;
  active?: boolean;
  afterId: number;
  limit: number;
}

/** A campaign from the admin panel. On update only the set fields are changed. */
export interface CampaignInput {
  slug?: string;
  trigger?: string;
  promo_code?: string;
  conditions?: Conditions;
  reward_type?: string;
  reward_params?: RewardParams;
  starts_at?: string;
  ends_at?: string;
  is_active?: boolean;
}

export type CampaignFields = Omit<Campaign, 'id' | 'createdAt'>;

/** Campaign fields before validation. */
export interface CampaignDraft {
  slug: string;
  trigger: string;
  promoCode: string | null;
  conditions: Conditions;
  rewardType: string;
  rewardParams: RewardParams;
  startsAt: Date | null;
  endsAt: Date | null;
  isActive: boolean;
  createdBy: string | null;
}

export function applyInput(d: CampaignDraft, input: CampaignInput): CampaignDraft {
  const out = { ...d };
  if (input.slug != null) out.slug = input.slug;
  if (input.trigger != null) out.trigger = input.trigger;
  if (input.promo_code != null) out.promoCode = input.promo_code;
  if (input.conditions != null) out.conditions = input.conditions;
  if (input.reward_type != null) out.rewardType = input.reward_type;
  if (input.reward_params != null) out.rewardParams = input.reward_params;
  if (input.starts_at != null) out.startsAt = parseDate('starts_at', input.starts_at);
  if (input.ends_at != null) out.endsAt = parseDate('ends_at', input.ends_at);
  if (input.is_active != null) out.isActive = input.is_active;
  return out;
}

export function validate(d: CampaignDraft): CampaignFields {
  if (!d.slug) {
    throw new InvalidCampaignError('slug is required');
  }

  let trigger: Trigger;
  switch (d.trigger) {
    case Trigger.PromoCode:
      if (!d.promoCode) {
        throw new InvalidCampaignError(`promo_code is required for trigger ${d.trigger}`);
      }
      trigger = d.trigger;
      break;
    case Trigger.PaymentSucceeded:
    case Trigger.UserRegistered:
      trigger = d.trigger;
      break;
    default:
      throw new InvalidCampaignError(`unknown trigger "${d.trigger}"`);
  }

  let rewardType: RewardType;
  switch (d.rewardType) {
    case RewardType.Discount:
      if (!d.rewardParams.percent && !d.rewardParams.amount) {
        throw new InvalidCampaignError('percent or amount is required');
      }
      rewardType = d.rewardType;
      break;
    case RewardType.Referral:
      if (!d.rewardParams.amount) {
        throw new InvalidCampaignError('amount is required');
      }
      rewardType = d.rewardType;
      break;
    default:
      throw new InvalidCampaignError(`unknown reward type "${d.rewardType}"`);
  }

  if (!d.startsAt) {
    throw new InvalidCampaignError('starts_at is required');
  }
  return { ...d, trigger, rewardType, startsAt: d.startsAt };
}

function parseDate(field: string, value: string): Date {
  const t = new Date(value);
  if (Number.isNaN(t.getTime())) {
    throw new InvalidCampaignError(`invalid ${field}`);
  }
  return t;
}
