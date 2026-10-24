// Consumer runtime required by the platform: commit after handle, per-key
// ordering, a processing deadline per message, a retry topic with backoff
// and a DLQ after the last attempt.
import { createHash } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';

import { Kafka, Partitioners, logLevel } from 'kafkajs';
import type { Consumer as KafkaConsumer, EachBatchPayload, IHeaders, KafkaMessage, Producer } from 'kafkajs';
import type { Logger } from 'pino';

import type { ConsumerConfig } from '../config/consumers.js';
import { InflightOffsets } from './offsets.js';

/** One Kafka record passed to a handler. */
export interface Message {
  topic: string;
  partition: number;
  offset: string;
  key: Buffer | null;
  value: Buffer | null;
  /** 1 for the first delivery, grows with every retry. */
  attempt: number;
}

/**
 * Processes one message. A resolved promise commits the offset. A rejected
 * one, as well as a handler that does not finish before the processing
 * deadline, sends the message to the retry topic, and after the last attempt
 * to the DLQ. The signal is aborted when the deadline passes.
 */
export interface MessageHandler {
  handle(msg: Message, signal: AbortSignal): Promise<void>;
}

const HEADER_ATTEMPT = 'x-attempt';
const HEADER_NOT_BEFORE = 'x-not-before';
const HEADER_ERROR = 'x-error';

export class Consumer {
  private readonly kafka: Kafka;
  private readonly producer: Producer;
  private readonly readers: KafkaConsumer[] = [];
  private readonly shutdown = new AbortController();

  constructor(
    brokers: string[],
    private readonly cfg: ConsumerConfig,
    private readonly handler: MessageHandler,
    private readonly log: Logger,
  ) {
    this.kafka = new Kafka({ clientId: 'loyalty', brokers, logLevel: logLevel.WARN });
    this.producer = this.kafka.producer({ createPartitioner: Partitioners.DefaultPartitioner });
  }

  /** Starts consuming the main and the retry topic. */
  async start(): Promise<void> {
    await this.producer.connect();
    await this.consume(this.cfg.topic, this.cfg.group);
    await this.consume(this.cfg.retryTopic, `${this.cfg.group}-retry`);
  }

  async stop(): Promise<void> {
    this.shutdown.abort();
    await Promise.all(this.readers.map((r) => r.disconnect()));
    await this.producer.disconnect();
  }

  private async consume(topic: string, groupId: string): Promise<void> {
    const reader = this.kafka.consumer({ groupId, sessionTimeout: this.cfg.sessionTimeoutMs });
    this.readers.push(reader);
    await reader.connect();
    await reader.subscribe({ topic, fromBeginning: this.cfg.fromBeginning });

    // Messages with the same key always go to the same slot, so they are
    // handled in order.
    const slots: Promise<unknown>[] = Array.from({ length: this.cfg.concurrency }, () => Promise.resolve());

    await reader.run({
      autoCommitInterval: this.cfg.commitIntervalMs,
      eachBatchAutoResolve: false,
      partitionsConsumedConcurrently: this.cfg.concurrency,
      eachBatch: async ({ batch, resolveOffset, heartbeat, isRunning }: EachBatchPayload) => {
        const inflight = new InflightOffsets(batch.messages.map((m) => m.offset));
        const handled = batch.messages.map((m) => {
          const i = slot(m.key, slots.length);
          const run = slots[i]!.then(async () => {
            if (!isRunning() || this.shutdown.signal.aborted) {
              throw new Error('consumer is stopping');
            }
            await this.process(batch.topic, batch.partition, m);
            const commit = inflight.done(m.offset);
            if (commit !== undefined) {
              resolveOffset(commit);
            }
            await heartbeat();
          });
          // The next message of the slot waits for this one, failed or not.
          slots[i] = run.catch(() => {});
          return run;
        });
        await Promise.all(handled);
      },
    });
  }

  private async process(topic: string, partition: number, m: KafkaMessage): Promise<void> {
    const attempt = attemptOf(m.headers);
    const notBefore = notBeforeOf(m.headers);
    if (notBefore !== undefined && notBefore > Date.now()) {
      await sleep(notBefore - Date.now(), undefined, { signal: this.shutdown.signal });
    }

    const signal = AbortSignal.any([this.shutdown.signal, AbortSignal.timeout(this.cfg.processingTimeoutMs)]);
    try {
      await untilAborted(
        this.handler.handle({ topic, partition, offset: m.offset, key: m.key, value: m.value, attempt }, signal),
        signal,
      );
      return;
    } catch (err) {
      if (this.shutdown.signal.aborted) {
        // Shutting down: the offset stays uncommitted, the message is
        // redelivered after restart.
        throw err;
      }
      await this.forward(topic, partition, m, attempt, err as Error);
    }
  }

  private async forward(topic: string, partition: number, m: KafkaMessage, attempt: number, cause: Error): Promise<void> {
    const log = this.log.child({ topic, partition, offset: m.offset, attempt, err: cause });
    if (attempt >= this.cfg.maxAttempts) {
      log.error('message sent to dlq');
      await this.producer.send({
        topic: this.cfg.dlqTopic,
        acks: -1,
        messages: [{ key: m.key, value: m.value, headers: forwardHeaders(attempt, undefined, cause) }],
      });
      return;
    }

    log.warn('message sent to retry');
    const notBefore = new Date(Date.now() + this.backoff(attempt));
    await this.producer.send({
      topic: this.cfg.retryTopic,
      acks: -1,
      messages: [{ key: m.key, value: m.value, headers: forwardHeaders(attempt + 1, notBefore, cause) }],
    });
  }

  private backoff(attempt: number): number {
    const b = this.cfg.backoffMs;
    if (b.length === 0) {
      return 0;
    }
    return b[Math.min(attempt - 1, b.length - 1)]!;
  }
}

/** Resolves with work, or rejects as soon as signal is aborted. */
function untilAborted(work: Promise<void>, signal: AbortSignal): Promise<void> {
  work.catch(() => {});
  return new Promise((resolve, reject) => {
    const onAbort = () => reject(signal.reason);
    if (signal.aborted) {
      onAbort();
      return;
    }
    signal.addEventListener('abort', onAbort, { once: true });
    work.then(resolve, reject).finally(() => signal.removeEventListener('abort', onAbort));
  });
}

function slot(key: Buffer | null, n: number): number {
  return createHash('md5')
    .update(key ?? Buffer.alloc(0))
    .digest()
    .readUInt32BE(0) % n;
}

function forwardHeaders(attempt: number, notBefore: Date | undefined, cause: Error): IHeaders {
  const headers: IHeaders = {
    [HEADER_ATTEMPT]: String(attempt),
    [HEADER_ERROR]: cause.message,
  };
  if (notBefore) {
    headers[HEADER_NOT_BEFORE] = notBefore.toISOString();
  }
  return headers;
}

function header(headers: IHeaders | undefined, name: string): string | undefined {
  const v = headers?.[name];
  const first = Array.isArray(v) ? v[0] : v;
  return first?.toString();
}

function attemptOf(headers: IHeaders | undefined): number {
  const n = Number(header(headers, HEADER_ATTEMPT));
  return Number.isInteger(n) && n > 0 ? n : 1;
}

function notBeforeOf(headers: IHeaders | undefined): number | undefined {
  const v = header(headers, HEADER_NOT_BEFORE);
  if (v === undefined) {
    return undefined;
  }
  const t = Date.parse(v);
  return Number.isNaN(t) ? undefined : t;
}
