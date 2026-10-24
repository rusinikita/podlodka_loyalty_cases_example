// Loads the service configuration from config/*.yaml.
import { readFileSync } from 'node:fs';
import { parse } from 'yaml';

import { parseDuration } from './duration.js';

export interface AppConfig {
  http: { port: number; shutdownGraceMs: number };
  database: { dsn: string; poolSize: number };
  promo: { maxPerMonth: number };
  discounts: { compensation: CompensationConfig };
  clients: { payoutGateway: ClientConfig; notifications: ClientConfig };
}

export interface CompensationConfig {
  maxPercent: number;
  ttlDays: number;
  role: string;
}

export interface ClientConfig {
  baseUrl: string;
  timeoutMs: number;
}

interface RawClient {
  base_url: string;
  timeout: string;
}

interface RawApp {
  http: { port: number; shutdown_grace: string };
  database: { dsn: string; pool_size: number };
  promo: { max_per_month: number };
  discounts: { compensation: { max_percent: number; ttl_days: number; role: string } };
  clients: { payout_gateway: RawClient; notifications: RawClient };
}

/** Reads app.yaml. ${VAR} references are replaced with environment variables. */
export function loadApp(path: string): AppConfig {
  const raw = load<RawApp>(path, true);
  return {
    http: { port: raw.http.port, shutdownGraceMs: parseDuration(raw.http.shutdown_grace) },
    database: { dsn: raw.database.dsn, poolSize: raw.database.pool_size },
    promo: { maxPerMonth: raw.promo.max_per_month },
    discounts: {
      compensation: {
        maxPercent: raw.discounts.compensation.max_percent,
        ttlDays: raw.discounts.compensation.ttl_days,
        role: raw.discounts.compensation.role,
      },
    },
    clients: {
      payoutGateway: client(raw.clients.payout_gateway),
      notifications: client(raw.clients.notifications),
    },
  };
}

function client(raw: RawClient): ClientConfig {
  return { baseUrl: raw.base_url, timeoutMs: parseDuration(raw.timeout) };
}

export interface WorkerConfig {
  name: string;
  schedule: string;
  runOnStart: boolean;
  batchSize: number;
}

interface RawWorkers {
  workers: Array<{ name: string; schedule: string; run_on_start?: boolean; batch_size?: number }>;
}

export function loadWorkers(path: string): WorkerConfig[] {
  const raw = load<RawWorkers>(path, false);
  return raw.workers.map((w) => ({
    name: w.name,
    schedule: w.schedule,
    runOnStart: w.run_on_start ?? false,
    batchSize: w.batch_size ?? 0,
  }));
}

/** Parses a schedule of the form "every <duration>" into milliseconds. */
export function workerInterval(w: WorkerConfig): number {
  const match = /^every\s+(.+)$/.exec(w.schedule);
  if (!match?.[1]) {
    throw new Error(`worker ${w.name}: unsupported schedule "${w.schedule}"`);
  }
  try {
    return parseDuration(match[1]);
  } catch (err) {
    throw new Error(`worker ${w.name}: ${(err as Error).message}`);
  }
}

export function load<T>(path: string, expandEnv: boolean): T {
  let text: string;
  try {
    text = readFileSync(path, 'utf8');
  } catch (err) {
    throw new Error(`read ${path}: ${(err as Error).message}`);
  }
  if (expandEnv) {
    text = text.replace(/\$\{(\w+)\}/g, (_, name: string) => process.env[name] ?? '');
  }
  try {
    return parse(text) as T;
  } catch (err) {
    throw new Error(`parse ${path}: ${(err as Error).message}`);
  }
}
