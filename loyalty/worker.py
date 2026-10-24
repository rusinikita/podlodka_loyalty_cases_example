"""Runs periodic jobs inside the service process."""

import asyncio
import time
from collections.abc import Awaitable, Callable

import structlog

Job = Callable[[], Awaitable[None]]


async def run(name: str, interval: float, run_on_start: bool, job: Job) -> None:
    """Calls job every interval seconds until cancelled. With run_on_start the
    first run happens right away, otherwise after the first interval. A failed
    run is logged; the next one happens on schedule."""
    log = structlog.get_logger().bind(worker=name)
    if run_on_start:
        await _run_once(job, log)
    while True:
        await asyncio.sleep(interval)
        await _run_once(job, log)


async def _run_once(job: Job, log: structlog.typing.FilteringBoundLogger) -> None:
    start = time.monotonic()
    try:
        await job()
    except Exception:
        log.exception("worker run failed", took=time.monotonic() - start)
        return
    log.info("worker run finished", took=time.monotonic() - start)
