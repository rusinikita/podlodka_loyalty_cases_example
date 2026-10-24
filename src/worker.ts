// Runs periodic jobs inside the service process.
import type { Logger } from 'pino';

export type Job = (signal: AbortSignal) => Promise<void>;

export interface Worker {
  stop(): Promise<void>;
}

/**
 * Calls job every intervalMs until stopped. With runOnStart the first run
 * happens right away, otherwise after the first interval. A failed run is
 * logged; the next one happens on schedule. A tick that comes while the
 * previous run is still going is skipped.
 */
export function startWorker(name: string, intervalMs: number, runOnStart: boolean, job: Job, logger: Logger): Worker {
  const log = logger.child({ worker: name });
  const controller = new AbortController();
  let running: Promise<void> | undefined;

  const tick = () => {
    if (running) {
      return;
    }
    running = runOnce(job, controller.signal, log).finally(() => {
      running = undefined;
    });
  };

  if (runOnStart) {
    tick();
  }
  const timer = setInterval(tick, intervalMs);

  return {
    async stop() {
      clearInterval(timer);
      controller.abort();
      await running;
    },
  };
}

async function runOnce(job: Job, signal: AbortSignal, log: Logger): Promise<void> {
  const start = Date.now();
  try {
    await job(signal);
    log.info({ took_ms: Date.now() - start }, 'worker run finished');
  } catch (err) {
    if (!signal.aborted) {
      log.error({ err, took_ms: Date.now() - start }, 'worker run failed');
    }
  }
}
