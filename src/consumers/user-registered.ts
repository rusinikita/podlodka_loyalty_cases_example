import type { Logger } from 'pino';

import { Trigger } from '../campaigns/campaign.js';
import type { Message, MessageHandler } from '../kafka/consumer.js';
import type { CampaignHandler } from './payment-succeeded.js';

/** Payload of users.user-registered (api/events/user-registered.yaml). */
interface UserRegisteredEvent {
  event_id: string;
  occurred_at: string;
  user_id: number;
  country: string;
  city_id: number;
  invited_by?: number | null;
}

export class UserRegisteredHandler implements MessageHandler {
  constructor(
    private readonly campaigns: CampaignHandler,
    private readonly log: Logger,
  ) {}

  /** Runs the user_registered campaigns for a new user. */
  async handle(msg: Message, signal: AbortSignal): Promise<void> {
    let evt: UserRegisteredEvent;
    try {
      evt = JSON.parse(msg.value?.toString('utf8') ?? '');
    } catch (err) {
      this.log.error({ partition: msg.partition, offset: msg.offset, err }, 'malformed registration event');
      return;
    }
    if (!evt?.user_id) {
      this.log.error(
        { event_id: evt?.event_id, partition: msg.partition, offset: msg.offset },
        'incomplete registration event',
      );
      return;
    }

    await this.campaigns.handle(
      {
        trigger: Trigger.UserRegistered,
        userId: evt.user_id,
        country: evt.country,
        cityId: evt.city_id,
        invitedBy: evt.invited_by ?? undefined,
      },
      signal,
    );
  }
}
