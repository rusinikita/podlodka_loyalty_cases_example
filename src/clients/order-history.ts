// Client of the order history service (api/order-history.yaml).
import type { ClientConfig } from '../config/config.js';

/** A non-200 response of the order history service. */
export class OrderHistoryError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
  ) {
    super(`order-history: ${status} ${code}: ${message}`);
  }
}

export class OrderHistoryClient {
  constructor(
    private readonly cfg: ClientConfig,
    private readonly token: string,
  ) {}

  /**
   * The number of the user's paid and completed orders for all time, of
   * every channel and payment method. An unknown user has none.
   */
  async countOrders(userId: number, signal?: AbortSignal): Promise<number> {
    const timeout = AbortSignal.timeout(this.cfg.timeoutMs);
    let resp: Response;
    try {
      resp = await fetch(`${this.cfg.baseUrl}/v1/users/${userId}/orders/count`, {
        headers: { 'X-Service-Token': this.token },
        signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
      });
    } catch (err) {
      throw new Error(`order-history: ${(err as Error).message}`, { cause: err });
    }

    if (resp.status === 200) {
      const body = (await resp.json()) as { count: number };
      return body.count;
    }
    if (resp.status === 404) {
      return 0;
    }

    const body = (await resp.json().catch(() => ({}))) as { code?: string; message?: string };
    throw new OrderHistoryError(resp.status, body.code ?? '', body.message ?? '');
  }
}
