import { load } from './config.js';
import { parseDuration } from './duration.js';

// Marks a value that the platform injects into the pod environment. It is
// the same for every service of the cluster.
const PLATFORM_DEFAULT = '${PLATFORM_DEFAULT}';

const ENV_PROCESSING_TIMEOUT = 'PLATFORM_CONSUMER_PROCESSING_TIMEOUT';
const ENV_RETRY_BACKOFF = 'PLATFORM_CONSUMER_RETRY_BACKOFF';

interface RawProcessing {
  ordering?: string;
  processing_timeout?: string;
  shutdown_grace?: string;
  concurrency?: number;
}

interface RawRetry {
  max_attempts?: number;
  backoff?: string;
  topic?: string;
  dlq?: string;
}

interface RawConsumer {
  name: string;
  topic: string;
  group: string;
  handler: string;
  partitions?: number;
  processing?: RawProcessing;
  retry?: RawRetry;
}

export interface ConsumersFile {
  service: string;
  kafka: { cluster: string };
  defaults: {
    auto_offset_reset?: string;
    session_timeout?: string;
    commit?: { mode?: string; interval?: string };
    processing?: RawProcessing;
    retry?: RawRetry;
  };
  consumers: RawConsumer[];
}

/** A consumer with defaults applied and platform values resolved. */
export interface ConsumerConfig {
  name: string;
  topic: string;
  group: string;
  handler: string;
  retryTopic: string;
  dlqTopic: string;
  fromBeginning: boolean;
  sessionTimeoutMs: number;
  commitIntervalMs: number;
  concurrency: number;
  processingTimeoutMs: number;
  maxAttempts: number;
  backoffMs: number[];
}

export function loadConsumers(path: string): ConsumersFile {
  return load<ConsumersFile>(path, false);
}

/** Broker list of the cluster, e.g. KAFKA_MAIN_EVENTS_BROKERS for cluster main-events. */
export function brokers(file: ConsumersFile): string[] {
  const env = `KAFKA_${file.kafka.cluster.replaceAll('-', '_').toUpperCase()}_BROKERS`;
  return (process.env[env] ?? '').split(',');
}

export function effective(file: ConsumersFile, c: RawConsumer): ConsumerConfig {
  const d = file.defaults;
  const fail = (field: string, err: unknown): never => {
    throw new Error(`consumer ${c.name}: ${field}: ${(err as Error).message}`);
  };

  let processingTimeoutMs = 0;
  try {
    processingTimeoutMs = parseDuration(
      resolve(c.processing?.processing_timeout || d.processing?.processing_timeout, ENV_PROCESSING_TIMEOUT),
    );
  } catch (err) {
    fail('processing_timeout', err);
  }

  let backoffMs: number[] = [];
  try {
    backoffMs = resolve(c.retry?.backoff || d.retry?.backoff, ENV_RETRY_BACKOFF)
      .split(',')
      .map((s) => parseDuration(s));
  } catch (err) {
    fail('retry.backoff', err);
  }

  return {
    name: c.name,
    topic: c.topic,
    group: c.group,
    handler: c.handler,
    retryTopic: c.retry?.topic ?? '',
    dlqTopic: c.retry?.dlq ?? '',
    fromBeginning: d.auto_offset_reset === 'earliest',
    sessionTimeoutMs: parseDuration(d.session_timeout ?? '30s'),
    commitIntervalMs: parseDuration(d.commit?.interval ?? '5s'),
    concurrency: c.processing?.concurrency || d.processing?.concurrency || 1,
    processingTimeoutMs,
    maxAttempts: c.retry?.max_attempts || d.retry?.max_attempts || 1,
    backoffMs,
  };
}

function resolve(value: string | undefined, env: string): string {
  if (value !== PLATFORM_DEFAULT) {
    return value ?? '';
  }
  const v = process.env[env];
  if (!v) {
    throw new Error(`${env} is not set`);
  }
  return v;
}
