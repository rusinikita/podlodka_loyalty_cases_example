// Client of the antifraud verdicts API (api/antifraud.yaml).
import type { ClientConfig } from '../config/config.js';

/** The largest number of rewards accepted in one request. */
export const MAX_BATCH = 500;

const SOURCE = 'loyalty';

export const Verdict = {
  Approved: 'approved',
  Rejected: 'rejected',
  Pending: 'pending',
} as const;
export type Verdict = (typeof Verdict)[keyof typeof Verdict];

export interface RewardCheck {
  reward_id: number;
  inviter_id: number;
  invitee_id: number;
  amount: number;
  currency: string;
  created_at: Date;
}

export interface RewardVerdict {
  reward_id: number;
  verdict: Verdict;
  reason?: string | null;
  checked_at?: string | null;
}

/** A non-200 response of the antifraud service. */
export class AntifraudError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
  ) {
    super(`antifraud: ${status} ${code}: ${message}`);
  }
}

export class AntifraudClient {
  constructor(
    private readonly cfg: ClientConfig,
    private readonly token: string,
  ) {}

  /**
   * Verdicts for up to MAX_BATCH rewards, in the order of the request.
   * A reward antifraud has not seen yet gets Verdict.Pending and a check is
   * started for it.
   */
  async verdicts(items: RewardCheck[], signal?: AbortSignal): Promise<RewardVerdict[]> {
    if (items.length === 0) {
      return [];
    }
    if (items.length > MAX_BATCH) {
      throw new Error(`antifraud: batch of ${items.length} rewards, at most ${MAX_BATCH} allowed`);
    }

    const timeout = AbortSignal.timeout(this.cfg.timeoutMs);
    let resp: Response;
    try {
      resp = await fetch(`${this.cfg.baseUrl}/v1/referral-rewards/verdicts`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Service-Token': this.token },
        body: JSON.stringify({ source: SOURCE, items }),
        signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
      });
    } catch (err) {
      throw new Error(`antifraud: ${(err as Error).message}`, { cause: err });
    }

    if (resp.status !== 200) {
      const body = (await resp.json().catch(() => ({}))) as { code?: string; message?: string };
      throw new AntifraudError(resp.status, body.code ?? '', body.message ?? '');
    }

    const out = (await resp.json()) as { items: RewardVerdict[] };
    if (out.items.length !== items.length) {
      throw new Error(`antifraud: got ${out.items.length} verdicts for ${items.length} rewards`);
    }
    return out.items;
  }
}
