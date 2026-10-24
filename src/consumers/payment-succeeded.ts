// Handlers of the Kafka topics the service reads. Topics, groups, deadlines
// and retries are set in config/consumers.yaml.
import type { Logger } from 'pino';

import { type CampaignEvent, Trigger } from '../campaigns/campaign.js';
import type { Message, MessageHandler } from '../kafka/consumer.js';

export interface CampaignHandler {
  handle(ev: CampaignEvent, signal?: AbortSignal): Promise<void>;
}

/** Payload of payments.payment-succeeded (api/events/payment-succeeded.yaml). */
interface PaymentSucceededEvent {
  event_id: string;
  occurred_at: string;
  payment_id: string;
  order_id: number;
  user_id: number;
  country: string;
  city_id: number;
  user_segment: string;
  amount: number;
  currency: string;
  payment_method: string;
  items?: Array<{ sku_id: number; quantity: number; price: number }>;
}

export class PaymentSucceededHandler implements MessageHandler {
  constructor(
    private readonly campaigns: CampaignHandler,
    private readonly log: Logger,
  ) {}

  /** Runs the payment_succeeded campaigns for a paid order. */
  async handle(msg: Message, signal: AbortSignal): Promise<void> {
    let evt: PaymentSucceededEvent;
    try {
      evt = JSON.parse(msg.value?.toString('utf8') ?? '');
    } catch (err) {
      // Redelivery will not fix a broken payload.
      this.log.error({ partition: msg.partition, offset: msg.offset, err }, 'malformed payment event');
      return;
    }
    if (!evt?.user_id || !evt.payment_id) {
      this.log.error(
        { event_id: evt?.event_id, partition: msg.partition, offset: msg.offset },
        'incomplete payment event',
      );
      return;
    }

    await this.campaigns.handle(
      {
        trigger: Trigger.PaymentSucceeded,
        userId: evt.user_id,
        country: evt.country,
        cityId: evt.city_id,
        segment: evt.user_segment,
        paymentId: evt.payment_id,
        paymentMethod: evt.payment_method,
        skuIds: (evt.items ?? []).map((it) => it.sku_id),
      },
      signal,
    );
  }
}
