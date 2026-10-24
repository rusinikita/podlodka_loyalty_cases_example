"""Consumer runtime required by the platform: commit after handle, per-key
ordering, a processing deadline per message, a retry topic with backoff and
a DLQ after the last attempt."""

import asyncio
import time
import zlib
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Protocol

import structlog
from aiokafka import AIOKafkaConsumer, AIOKafkaProducer, ConsumerRecord, TopicPartition

from loyalty.config.consumers import ConsumerConfig
from loyalty.kafka.offsets import InflightOffsets

HEADER_ATTEMPT = "x-attempt"
HEADER_NOT_BEFORE = "x-not-before"
HEADER_ERROR = "x-error"


@dataclass(frozen=True)
class Message:
    """One Kafka record passed to a handler."""

    topic: str
    partition: int
    offset: int
    key: bytes | None
    value: bytes
    # 1 for the first delivery, grows with every retry.
    attempt: int


class Handler(Protocol):
    """Processes one message. Returning commits the offset. An exception, as
    well as a handler that does not finish before the processing deadline,
    sends the message to the retry topic, and after the last attempt to the DLQ."""

    async def handle(self, msg: Message) -> None: ...


class Consumer:
    def __init__(self, brokers: list[str], cfg: ConsumerConfig, handler: Handler) -> None:
        self._brokers = brokers
        self._cfg = cfg
        self._handler = handler
        self._log = structlog.get_logger().bind(consumer=cfg.name)
        self._producer = AIOKafkaProducer(bootstrap_servers=brokers, acks="all")

    async def run(self) -> None:
        """Consumes the main and the retry topic until cancelled."""
        await self._producer.start()
        try:
            async with asyncio.TaskGroup() as tg:
                tg.create_task(self._consume(self._cfg.topic, self._cfg.group))
                tg.create_task(self._consume(self._cfg.retry_topic, self._cfg.group + "-retry"))
        finally:
            await self._producer.stop()

    async def _consume(self, topic: str, group: str) -> None:
        reader = AIOKafkaConsumer(
            topic,
            bootstrap_servers=self._brokers,
            group_id=group,
            enable_auto_commit=False,
            auto_offset_reset=self._cfg.auto_offset_reset,
            session_timeout_ms=int(self._cfg.session_timeout.total_seconds() * 1000),
        )
        await reader.start()
        inflight = InflightOffsets()
        pending: dict[TopicPartition, int] = {}

        async def handle_slot(queue: asyncio.Queue[ConsumerRecord[bytes, bytes]]) -> None:
            while True:
                record = await queue.get()
                await self._process(record)
                committed = inflight.done(record.partition, record.offset)
                if committed is not None:
                    pending[TopicPartition(record.topic, record.partition)] = committed + 1

        async def commit_loop() -> None:
            while True:
                await asyncio.sleep(self._cfg.commit_interval.total_seconds())
                if pending:
                    offsets = dict(pending)
                    pending.clear()
                    await reader.commit(offsets)

        # Messages with the same key always go to the same slot, so they are
        # handled in order.
        slots: list[asyncio.Queue[ConsumerRecord[bytes, bytes]]] = [
            asyncio.Queue(maxsize=1) for _ in range(self._cfg.concurrency)
        ]
        try:
            async with asyncio.TaskGroup() as tg:
                for q in slots:
                    tg.create_task(handle_slot(q))
                tg.create_task(commit_loop())
                async for record in reader:
                    inflight.fetched(record.partition, record.offset)
                    await slots[_slot(record.key, len(slots))].put(record)
        finally:
            await reader.stop()

    async def _process(self, record: ConsumerRecord[bytes, bytes]) -> None:
        headers = dict(record.headers)
        attempt = _attempt_of(headers)
        not_before = _not_before_of(headers)
        if not_before is not None and not_before > time.time():
            await asyncio.sleep(not_before - time.time())

        try:
            async with asyncio.timeout(self._cfg.processing_timeout.total_seconds()):
                await self._handler.handle(
                    Message(
                        topic=record.topic,
                        partition=record.partition,
                        offset=record.offset,
                        key=record.key,
                        value=record.value or b"",
                        attempt=attempt,
                    )
                )
        except Exception as e:
            # On shutdown the task is cancelled: CancelledError is not caught
            # here, the offset stays uncommitted and the message is redelivered
            # after restart.
            await self._forward(record, attempt, e)

    async def _forward(self, record: ConsumerRecord[bytes, bytes], attempt: int, cause: Exception) -> None:
        log = self._log.bind(
            topic=record.topic, partition=record.partition, offset=record.offset, attempt=attempt, err=repr(cause)
        )
        if attempt >= self._cfg.max_attempts:
            log.error("message sent to dlq")
            await self._producer.send_and_wait(
                self._cfg.dlq_topic, record.value, key=record.key, headers=_forward_headers(attempt, None, cause)
            )
            return

        log.warning("message sent to retry")
        not_before = time.time() + self._backoff(attempt)
        await self._producer.send_and_wait(
            self._cfg.retry_topic,
            record.value,
            key=record.key,
            headers=_forward_headers(attempt + 1, not_before, cause),
        )

    def _backoff(self, attempt: int) -> float:
        b = self._cfg.backoff
        if not b:
            return 0.0
        return b[min(attempt - 1, len(b) - 1)].total_seconds()


def _slot(key: bytes | None, n: int) -> int:
    return zlib.crc32(key or b"") % n


def _forward_headers(attempt: int, not_before: float | None, cause: Exception) -> list[tuple[str, bytes]]:
    headers = [
        (HEADER_ATTEMPT, str(attempt).encode()),
        (HEADER_ERROR, (str(cause) or type(cause).__name__).encode()),
    ]
    if not_before is not None:
        headers.append((HEADER_NOT_BEFORE, datetime.fromtimestamp(not_before).astimezone().isoformat().encode()))
    return headers


def _attempt_of(headers: dict[str, Any]) -> int:
    try:
        n = int(headers[HEADER_ATTEMPT])
    except (KeyError, ValueError):
        return 1
    return n if n > 0 else 1


def _not_before_of(headers: dict[str, Any]) -> float | None:
    try:
        return datetime.fromisoformat(headers[HEADER_NOT_BEFORE].decode()).timestamp()
    except (KeyError, ValueError):
        return None
