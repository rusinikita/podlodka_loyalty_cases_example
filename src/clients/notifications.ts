// Client of the CRM notifications service (api/notifications.yaml).
import type { ClientConfig } from '../config/config.js';

export interface Push {
  user_id: number;
  template: string;
  params?: Record<string, string>;
}

export class NotificationsClient {
  constructor(
    private readonly cfg: ClientConfig,
    private readonly token: string,
  ) {}

  async push(p: Push, signal?: AbortSignal): Promise<void> {
    const timeout = AbortSignal.timeout(this.cfg.timeoutMs);
    let resp: Response;
    try {
      resp = await fetch(`${this.cfg.baseUrl}/v1/push`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Service-Token': this.token },
        body: JSON.stringify(p),
        signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
      });
    } catch (err) {
      throw new Error(`notifications: ${(err as Error).message}`, { cause: err });
    }
    if (resp.status !== 202) {
      throw new Error(`notifications: unexpected status ${resp.status}`);
    }
  }
}
