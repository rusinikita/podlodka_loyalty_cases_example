// User discounts: granting by a campaign or as a compensation, picking
// a discount for an order and redeeming it.

export class DiscountUnavailableError extends Error {
  constructor() {
    super('discount is used or expired');
  }
}

export class CompensationLimitError extends Error {
  constructor() {
    super('compensation is above the limits');
  }
}

export class CommentRequiredError extends Error {
  constructor() {
    super('comment with the ticket number is required');
  }
}

/** A row of user_discounts. Either percent (with an optional maxAmount cap) or amount is set. */
export interface Discount {
  id: number;
  userId: number;
  campaignId: number | null;
  channel: string | null;
  paymentId: string | null;
  percent: number | null;
  amount: number | null;
  maxAmount: number | null;
  expiresAt: Date;
  usedAt: Date | null;
  usedOrderId: number | null;
  createdBy: string | null;
  comment: string | null;
  createdAt: Date;
}

export type NewDiscount = Omit<Discount, 'id' | 'usedAt' | 'usedOrderId' | 'createdAt'>;

/** The discount for an order of orderAmount. */
export function discountValue(d: Discount, orderAmount: number): number {
  if (d.percent !== null) {
    const v = Math.trunc((orderAmount * d.percent) / 100);
    return d.maxAmount !== null && v > d.maxAmount ? d.maxAmount : v;
  }
  if (d.amount !== null) {
    return Math.min(d.amount, orderAmount);
  }
  return 0;
}

/** A discount granted by a campaign. */
export interface GrantRequest {
  userId: number;
  campaignId: number;
  channel?: string;
  paymentId?: string;
  percent?: number;
  amount?: number;
  maxAmount?: number;
  expiresAt: Date;
  /** Sends the user a push about the discount for the next order. */
  notify: boolean;
}

/** A discount granted by an operator in the admin panel. */
export interface CompensationRequest {
  userId: number;
  percent: number;
  maxAmount: number | null;
  ttlDays: number;
  operator: string;
  comment: string;
}

export interface QuoteRequest {
  userId: number;
  orderAmount: number;
  cityId: number;
  /** RFC 3339 time of the order in the city's time zone. */
  orderedAt: string;
}

export interface Quote {
  discountId: number | null;
  amount: number;
}

/** Campaign conditions checked when a discount is used for an order. */
export interface OrderConditions {
  time_from?: string;
  time_to?: string;
  min_order_amount?: number;
}

/** orderedAt is an RFC 3339 time; its local "HH:MM" is compared with the interval. */
export function matchOrder(c: OrderConditions, orderAmount: number, orderedAt: string): boolean {
  if (c.min_order_amount && orderAmount < c.min_order_amount) {
    return false;
  }
  if (!c.time_from || !c.time_to) {
    return true;
  }
  const hm = orderedAt.slice(11, 16);
  if (c.time_from <= c.time_to) {
    return hm >= c.time_from && hm < c.time_to;
  }
  // The interval crosses midnight, e.g. 22:00–06:00.
  return hm >= c.time_from || hm < c.time_to;
}

export function discountView(d: Discount) {
  return {
    id: d.id,
    campaign_id: d.campaignId,
    percent: d.percent,
    amount: d.amount,
    max_amount: d.maxAmount,
    expires_at: d.expiresAt,
    used_at: d.usedAt,
    created_at: d.createdAt,
  };
}
