"""Runs the loyalty service: HTTP API, Kafka consumers and workers in one process."""

import asyncio
import logging
import os
import signal
from collections.abc import Awaitable, Callable, Coroutine
from functools import partial
from pathlib import Path
from typing import Any

import structlog
import uvicorn
from fastapi import FastAPI, Response

from loyalty import worker
from loyalty.admin.router import admin_router
from loyalty.campaigns.repository import PostgresCampaignRepository
from loyalty.campaigns.service import CampaignService
from loyalty.clients.antifraud import AntifraudClient
from loyalty.clients.notifications import NotificationsClient
from loyalty.clients.payout_gateway import PayoutGatewayClient
from loyalty.config.app import load_app, load_workers
from loyalty.config.consumers import effective, load_consumers
from loyalty.consumers.payment_succeeded import PaymentSucceededHandler
from loyalty.consumers.user_registered import UserRegisteredHandler
from loyalty.db import connect
from loyalty.discounts.repository import PostgresDiscountRepository
from loyalty.discounts.service import DiscountService
from loyalty.http import install_error_handlers
from loyalty.httpapi.router import app_router
from loyalty.kafka.consumer import Consumer, Handler
from loyalty.promo.service import PromoService
from loyalty.referrals.repository import PostgresRewardRepository
from loyalty.referrals.service import ReferralService

log = structlog.get_logger()


async def run() -> None:
    config_dir = Path(os.environ.get("LOYALTY_CONFIG_DIR") or "config")
    app_cfg = load_app(config_dir / "app.yaml")
    consumers_file = load_consumers(config_dir / "consumers.yaml")
    workers_cfg = load_workers(config_dir / "workers.yaml")

    db = await connect(app_cfg.database.dsn, app_cfg.database.pool_size)

    service_token = os.environ.get("LOYALTY_SERVICE_TOKEN", "")
    clients = app_cfg.clients
    payouts = PayoutGatewayClient(
        clients.payout_gateway.base_url, service_token, clients.payout_gateway.timeout.total_seconds()
    )
    notifications = NotificationsClient(
        clients.notifications.base_url, service_token, clients.notifications.timeout.total_seconds()
    )
    antifraud = AntifraudClient(clients.antifraud.base_url, service_token, clients.antifraud.timeout.total_seconds())

    discount_service = DiscountService(PostgresDiscountRepository(db), notifications, app_cfg.discounts.compensation)
    referral_service = ReferralService(PostgresRewardRepository(db), payouts, antifraud)
    campaign_service = CampaignService(
        PostgresCampaignRepository(db), discount_service, referral_service, app_cfg.promo.max_per_month
    )
    promo_service = PromoService(campaign_service)

    app = FastAPI(title="loyalty", docs_url=None, redoc_url=None, openapi_url=None)
    install_error_handlers(app)
    app.add_api_route("/healthz", lambda: Response(status_code=200))
    app.include_router(
        app_router(promo_service, discount_service, referral_service, os.environ.get("LOYALTY_ORDERS_TOKEN", "")),
        prefix="/v1",
    )
    app.include_router(
        admin_router(campaign_service, discount_service, referral_service, app_cfg.discounts.compensation.role),
        prefix="/admin/v1",
    )
    server = uvicorn.Server(
        uvicorn.Config(
            app,
            host="0.0.0.0",
            port=app_cfg.http.port,
            log_config=None,
            proxy_headers=True,
            timeout_graceful_shutdown=int(app_cfg.http.shutdown_grace.total_seconds()),
        )
    )

    handlers: dict[str, Handler] = {
        "payment_succeeded": PaymentSucceededHandler(campaign_service),
        "user_registered": UserRegisteredHandler(campaign_service),
    }
    consumers: list[Consumer] = []
    for cc in consumers_file.consumers:
        handler = handlers.get(cc.handler)
        if handler is None:
            raise ValueError(f"consumer {cc.name}: unknown handler {cc.handler!r}")
        consumers.append(Consumer(consumers_file.kafka.brokers(), effective(consumers_file, cc), handler))

    jobs: dict[str, Callable[[int], Awaitable[None]]] = {
        "reward-payouts": referral_service.pay_pending,
        "reward-antifraud-check": referral_service.check_antifraud,
    }
    workers: list[Callable[[], Coroutine[Any, Any, None]]] = []
    for w in workers_cfg.workers:
        job = jobs.get(w.name)
        if job is None:
            raise ValueError(f"unknown worker {w.name!r}")
        workers.append(partial(worker.run, w.name, w.interval(), w.run_on_start, partial(job, w.batch_size)))

    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        loop.add_signal_handler(sig, stop.set)

    try:
        async with asyncio.TaskGroup() as tg:
            serving = tg.create_task(server.serve())
            serving.add_done_callback(lambda _: stop.set())
            background = [tg.create_task(c.run()) for c in consumers]
            background += [tg.create_task(w()) for w in workers]

            await stop.wait()
            server.should_exit = True
            for t in background:
                t.cancel()
    finally:
        await payouts.aclose()
        await notifications.aclose()
        await antifraud.aclose()
        await db.close()


def main() -> None:
    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso", utc=True),
            structlog.processors.format_exc_info,
            structlog.processors.JSONRenderer(),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(logging.INFO),
    )
    try:
        asyncio.run(run())
    except Exception as e:
        log.error("loyalty stopped", err=repr(e))
        raise SystemExit(1) from e


if __name__ == "__main__":
    main()
