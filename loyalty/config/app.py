import os
from pathlib import Path
from typing import Any

import yaml
from pydantic import BaseModel

from loyalty.config.durations import Duration, parse_duration


class HTTPConfig(BaseModel):
    port: int
    shutdown_grace: Duration


class DatabaseConfig(BaseModel):
    dsn: str
    pool_size: int = 0


class PromoConfig(BaseModel):
    max_per_month: int


class CompensationConfig(BaseModel):
    max_percent: int
    ttl_days: int
    role: str


class DiscountsConfig(BaseModel):
    compensation: CompensationConfig


class ClientConfig(BaseModel):
    base_url: str
    timeout: Duration


class ClientsConfig(BaseModel):
    payout_gateway: ClientConfig
    notifications: ClientConfig


class AppConfig(BaseModel):
    http: HTTPConfig
    database: DatabaseConfig
    promo: PromoConfig
    discounts: DiscountsConfig
    clients: ClientsConfig


class WorkerConfig(BaseModel):
    name: str
    schedule: str
    run_on_start: bool = False
    batch_size: int

    def interval(self) -> float:
        """Seconds between runs, from a schedule of the form "every <duration>"."""
        prefix = "every "
        if not self.schedule.startswith(prefix):
            raise ValueError(f"worker {self.name}: unsupported schedule {self.schedule!r}")
        try:
            return parse_duration(self.schedule.removeprefix(prefix)).total_seconds()
        except ValueError as e:
            raise ValueError(f"worker {self.name}: {e}") from e


class WorkersConfig(BaseModel):
    workers: list[WorkerConfig]


def load_app(path: Path) -> AppConfig:
    """Reads app.yaml. ${VAR} references are replaced with environment variables."""
    return AppConfig.model_validate(load(path, expand_env=True))


def load_workers(path: Path) -> WorkersConfig:
    return WorkersConfig.model_validate(load(path))


def load(path: Path, expand_env: bool = False) -> Any:
    raw = path.read_text()
    if expand_env:
        raw = os.path.expandvars(raw)
    return yaml.safe_load(raw)
