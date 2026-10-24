import os
from dataclasses import dataclass
from datetime import timedelta
from pathlib import Path

from pydantic import BaseModel

from loyalty.config.app import load
from loyalty.config.durations import Duration, parse_duration

# Marks a value that the platform injects into the pod environment. It is
# the same for every service of the cluster.
PLATFORM_DEFAULT = "${PLATFORM_DEFAULT}"

ENV_PROCESSING_TIMEOUT = "PLATFORM_CONSUMER_PROCESSING_TIMEOUT"
ENV_RETRY_BACKOFF = "PLATFORM_CONSUMER_RETRY_BACKOFF"


class KafkaSettings(BaseModel):
    cluster: str

    def brokers(self) -> list[str]:
        """Broker list of the cluster, e.g. KAFKA_MAIN_EVENTS_BROKERS for cluster main-events."""
        env = "KAFKA_" + self.cluster.replace("-", "_").upper() + "_BROKERS"
        return os.environ.get(env, "").split(",")


class Commit(BaseModel):
    mode: str = ""
    interval: Duration = timedelta(seconds=5)


class Processing(BaseModel):
    ordering: str = ""
    processing_timeout: str = ""
    shutdown_grace: Duration | None = None
    concurrency: int = 0


class Retry(BaseModel):
    max_attempts: int = 0
    backoff: str = ""
    topic: str = ""
    dlq: str = ""


class Defaults(BaseModel):
    auto_offset_reset: str = "latest"
    session_timeout: Duration = timedelta(seconds=30)
    commit: Commit = Commit()
    processing: Processing = Processing()
    retry: Retry = Retry()


class Consumer(BaseModel):
    name: str
    topic: str
    group: str
    handler: str
    partitions: int = 0
    processing: Processing = Processing()
    retry: Retry = Retry()


class ConsumersFile(BaseModel):
    service: str
    kafka: KafkaSettings
    defaults: Defaults
    consumers: list[Consumer]


@dataclass(frozen=True)
class ConsumerConfig:
    """A consumer with defaults applied and platform values resolved."""

    name: str
    topic: str
    group: str
    retry_topic: str
    dlq_topic: str
    auto_offset_reset: str
    session_timeout: timedelta
    commit_interval: timedelta
    concurrency: int
    processing_timeout: timedelta
    max_attempts: int
    backoff: list[timedelta]


def load_consumers(path: Path) -> ConsumersFile:
    return ConsumersFile.model_validate(load(path))


def effective(f: ConsumersFile, c: Consumer) -> ConsumerConfig:
    d = f.defaults
    try:
        timeout = parse_duration(
            _resolve(c.processing.processing_timeout or d.processing.processing_timeout, ENV_PROCESSING_TIMEOUT)
        )
    except ValueError as e:
        raise ValueError(f"consumer {c.name}: processing_timeout: {e}") from e
    try:
        backoff = [
            parse_duration(s) for s in _resolve(c.retry.backoff or d.retry.backoff, ENV_RETRY_BACKOFF).split(",")
        ]
    except ValueError as e:
        raise ValueError(f"consumer {c.name}: retry.backoff: {e}") from e

    return ConsumerConfig(
        name=c.name,
        topic=c.topic,
        group=c.group,
        retry_topic=c.retry.topic,
        dlq_topic=c.retry.dlq,
        auto_offset_reset=d.auto_offset_reset,
        session_timeout=d.session_timeout,
        commit_interval=d.commit.interval,
        concurrency=c.processing.concurrency or d.processing.concurrency or 1,
        processing_timeout=timeout,
        max_attempts=c.retry.max_attempts or d.retry.max_attempts or 1,
        backoff=backoff,
    )


def _resolve(value: str, env: str) -> str:
    if value != PLATFORM_DEFAULT:
        return value
    v = os.environ.get(env, "")
    if not v:
        raise ValueError(f"{env} is not set")
    return v
