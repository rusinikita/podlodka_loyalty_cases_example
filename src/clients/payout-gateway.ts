// Client of payout-gateway (api/payout-gateway.yaml).
import type { ClientConfig } from '../config/config.js';

export class NoPayoutMethodError extends Error {
  constructor() {
    super('no payout method');
  }
}

export class UserBlockedError extends Error {
  constructor() {
    super('user blocked');
  }
}

/** A response the client does not map to a dedicated error. */
export class PayoutGatewayError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
  ) {
    super(`payout-gateway: ${status} ${code}: ${message}`);
  }
}

export interface PayoutRequest {
  idempotency_key: string;
  user_id: number;
  amount: number;
  currency: string;
  purpose: string;
}

export interface Payout {
  payout_id: string;
  status: string;
  card_mask?: string;
  created_at: string;
}

export class PayoutGatewayClient {
  constructor(
    private readonly cfg: ClientConfig,
    private readonly token: string,
  ) {}

  /** Creates a payout. A repeated call with the same idempotency key returns the original payout. */
  async pay(req: PayoutRequest, signal?: AbortSignal): Promise<Payout> {
    const timeout = AbortSignal.timeout(this.cfg.timeoutMs);
    let resp: Response;
    try {
      resp = await fetch(`${this.cfg.baseUrl}/v1/payouts`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Service-Token': this.token },
        body: JSON.stringify(req),
        signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
      });
    } catch (err) {
      throw new Error(`payout-gateway: ${(err as Error).message}`, { cause: err });
    }

    if (resp.status === 200) {
      return (await resp.json()) as Payout;
    }

    const body = (await resp.json().catch(() => ({}))) as { code?: string; message?: string };
    switch (body.code) {
      case 'no_payout_method':
        throw new NoPayoutMethodError();
      case 'user_blocked':
        throw new UserBlockedError();
    }
    throw new PayoutGatewayError(resp.status, body.code ?? '', body.message ?? '');
  }
}
