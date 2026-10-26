// Runs the loyalty service: HTTP API, Kafka consumers and workers in one process.
import type { Server } from 'node:http';
import { join } from 'node:path';

import express from 'express';
import { pino } from 'pino';

import { adminRouter } from './admin/router.js';
import { PostgresCampaignRepository } from './campaigns/repository.js';
import { CampaignService } from './campaigns/service.js';
import { NotificationsClient } from './clients/notifications.js';
import { OrderHistoryClient } from './clients/order-history.js';
import { PayoutGatewayClient } from './clients/payout-gateway.js';
import { loadApp, loadWorkers, workerInterval } from './config/config.js';
import { brokers, effective, loadConsumers } from './config/consumers.js';
import { PaymentSucceededHandler } from './consumers/payment-succeeded.js';
import { UserRegisteredHandler } from './consumers/user-registered.js';
import { connect } from './db.js';
import { PostgresDiscountRepository } from './discounts/repository.js';
import { DiscountService } from './discounts/service.js';
import { errorHandler } from './http.js';
import { appRouter } from './httpapi/router.js';
import { Consumer, type MessageHandler } from './kafka/consumer.js';
import { PromoService } from './promo/service.js';
import { PostgresRewardRepository } from './referrals/repository.js';
import { ReferralService } from './referrals/service.js';
import { type Job, startWorker, type Worker } from './worker.js';

const log = pino();

async function main(): Promise<void> {
  const dir = process.env.LOYALTY_CONFIG_DIR || 'config';
  const app = loadApp(join(dir, 'app.yaml'));
  const consumersFile = loadConsumers(join(dir, 'consumers.yaml'));
  const workersCfg = loadWorkers(join(dir, 'workers.yaml'));

  const db = await connect(app.database.dsn, app.database.poolSize);

  const serviceToken = process.env.LOYALTY_SERVICE_TOKEN ?? '';
  const payouts = new PayoutGatewayClient(app.clients.payoutGateway, serviceToken);
  const notifications = new NotificationsClient(app.clients.notifications, serviceToken);
  const orders = new OrderHistoryClient(app.clients.orderHistory, serviceToken);

  const discountService = new DiscountService(
    new PostgresDiscountRepository(db),
    notifications,
    app.discounts.compensation,
    log,
  );
  const referralService = new ReferralService(new PostgresRewardRepository(db), payouts, log);
  const campaignService = new CampaignService(
    new PostgresCampaignRepository(db),
    discountService,
    referralService,
    orders,
    app.promo.maxPerMonth,
    log,
  );
  const promoService = new PromoService(campaignService, log);

  const http = express();
  http.set('trust proxy', true);
  http.use(express.json());
  http.get('/healthz', (_req, res) => {
    res.status(200).end();
  });
  http.use(
    '/v1',
    appRouter(promoService, discountService, referralService, process.env.LOYALTY_ORDERS_TOKEN ?? ''),
  );
  http.use(
    '/admin/v1',
    adminRouter(campaignService, discountService, referralService, app.discounts.compensation.role),
  );
  http.use(errorHandler(log));

  const handlers: Record<string, MessageHandler> = {
    payment_succeeded: new PaymentSucceededHandler(campaignService, log),
    user_registered: new UserRegisteredHandler(campaignService, log),
  };
  const consumers: Consumer[] = [];
  for (const raw of consumersFile.consumers) {
    const handler = handlers[raw.handler];
    if (!handler) {
      throw new Error(`consumer ${raw.name}: unknown handler "${raw.handler}"`);
    }
    const cfg = effective(consumersFile, raw);
    consumers.push(new Consumer(brokers(consumersFile), cfg, handler, log.child({ consumer: raw.name })));
  }

  const jobs: Record<string, (batchSize: number, signal: AbortSignal) => Promise<void>> = {
    'reward-payouts': (batchSize, signal) => referralService.payPending(batchSize, signal),
  };
  const workerJobs: Array<{ name: string; intervalMs: number; runOnStart: boolean; job: Job }> = [];
  for (const w of workersCfg) {
    const job = jobs[w.name];
    if (!job) {
      throw new Error(`unknown worker "${w.name}"`);
    }
    workerJobs.push({
      name: w.name,
      intervalMs: workerInterval(w),
      runOnStart: w.runOnStart,
      job: (signal) => job(w.batchSize, signal),
    });
  }

  const server: Server = http.listen(app.http.port, () => {
    log.info({ port: app.http.port }, 'http server started');
  });
  await Promise.all(consumers.map((c) => c.start()));
  const workers: Worker[] = workerJobs.map((w) => startWorker(w.name, w.intervalMs, w.runOnStart, w.job, log));

  const shutdown = async (signal: string) => {
    log.info({ signal }, 'shutting down');
    const grace = setTimeout(() => {
      log.error('shutdown grace period exceeded');
      process.exit(1);
    }, app.http.shutdownGraceMs);
    grace.unref();

    await new Promise<void>((resolve) => server.close(() => resolve()));
    await Promise.all([...consumers.map((c) => c.stop()), ...workers.map((w) => w.stop())]);
    await db.end();
  };
  process.once('SIGINT', () => void shutdown('SIGINT'));
  process.once('SIGTERM', () => void shutdown('SIGTERM'));
}

main().catch((err: unknown) => {
  log.error({ err }, 'loyalty stopped');
  process.exit(1);
});
