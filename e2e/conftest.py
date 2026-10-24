"""Test environment, one per session: Postgres with the migrations applied,
a single-node Kafka, the mock of the external APIs and the service built from
the Dockerfile in the repository root.

The service gets a copy of config/ in which the clients point to the mock,
the workers run every few seconds and the consumers read the topics from the
beginning. The rest of the configuration is the same as in production.

Files are copied into the containers, nothing is mounted from the host. With
Colima set TESTCONTAINERS_DOCKER_SOCKET_OVERRIDE=/var/run/docker.sock."""

import json
import socket
import sys
import time
from collections.abc import Callable, Iterator
from pathlib import Path
from typing import Any

import httpx
import pytest
import yaml
from confluent_kafka import KafkaException, Producer
from confluent_kafka.admin import AdminClient, NewTopic
from testcontainers.core.container import DockerContainer
from testcontainers.core.image import DockerImage
from testcontainers.core.network import Network

from mock_server import MockServer
from support import ORDERS_TOKEN

ROOT = Path(__file__).resolve().parent.parent
CONFIG_DIR = ROOT / "config"
MIGRATIONS_DIR = ROOT / "migrations"

POSTGRES_IMAGE = "postgres:16-alpine"
KAFKA_IMAGE = "apache/kafka:3.9.1"
APP_IMAGE = "loyalty-e2e:local"

DB_DSN = "postgres://loyalty:loyalty@postgres:5432/loyalty?sslmode=disable"
SERVICE_TOKEN = "e2e-service-token"
WORKER_SCHEDULE = "every 2s"

APP_ENV = {
    "LOYALTY_CONFIG_DIR": "/etc/loyalty",
    "LOYALTY_DB_DSN": DB_DSN,
    "KAFKA_MAIN_EVENTS_BROKERS": "kafka:9092",
    "LOYALTY_SERVICE_TOKEN": SERVICE_TOKEN,
    "LOYALTY_ORDERS_TOKEN": ORDERS_TOKEN,
    "PLATFORM_CONSUMER_PROCESSING_TIMEOUT": "30s",
    "PLATFORM_CONSUMER_RETRY_BACKOFF": "1s",
}


def wait_until(check: Callable[[], bool], timeout: float, what: str) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            if check():
                return
        except (httpx.HTTPError, KafkaException):
            pass
        time.sleep(0.5)
    raise TimeoutError(f"{what} is not ready after {timeout:.0f}s")


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def prepare_config(mock_url: str) -> tuple[dict[str, bytes], list[str]]:
    """Returns the test copy of config/ by file name and the topics the
    service reads and writes."""
    files = {}
    for src in CONFIG_DIR.glob("*.yaml"):
        doc = yaml.safe_load(src.read_text())
        if src.name == "app.yaml":
            for client in doc.get("clients", {}).values():
                client["base_url"] = mock_url
        if src.name == "workers.yaml":
            for worker in doc["workers"]:
                worker["schedule"] = WORKER_SCHEDULE
        if src.name == "consumers.yaml":
            doc["defaults"]["auto_offset_reset"] = "earliest"
        files[src.name] = yaml.safe_dump(doc, sort_keys=False, allow_unicode=True).encode()

    consumers = yaml.safe_load((CONFIG_DIR / "consumers.yaml").read_text())["consumers"]
    topics = []
    for c in consumers:
        topics += [c["topic"], c["retry"]["topic"], c["retry"]["dlq"]]
    return files, topics


def pytest_collection_modifyitems(items: list[pytest.Item]) -> None:
    if not (ROOT / "Dockerfile").exists():
        skip = pytest.mark.skip(reason="no Dockerfile in the repository root")
        for item in items:
            item.add_marker(skip)


@pytest.fixture(scope="session")
def network() -> Iterator[Network]:
    with Network() as net:
        yield net


@pytest.fixture(scope="session")
def postgres(network: Network) -> Iterator[DockerContainer]:
    migrations = sorted(MIGRATIONS_DIR.glob("*.sql"))
    container = (
        DockerContainer(POSTGRES_IMAGE)
        .with_network(network)
        .with_network_aliases("postgres")
        .with_envs(POSTGRES_USER="loyalty", POSTGRES_PASSWORD="loyalty", POSTGRES_DB="loyalty")
    )
    for migration in migrations:
        container.with_copy_into_container(migration, f"/migrations/{migration.name}")
    with container:
        # pg_isready over TCP: during initdb the server listens on the socket only.
        wait_until(
            lambda: container.exec("pg_isready -h 127.0.0.1 -U loyalty -d loyalty").exit_code == 0,
            60,
            "postgres",
        )
        for migration in migrations:
            result = container.exec(
                f"psql -h 127.0.0.1 -U loyalty -d loyalty -v ON_ERROR_STOP=1 -q -f /migrations/{migration.name}"
            )
            assert result.exit_code == 0, f"{migration.name}: {result.output.decode()}"
        yield container


@pytest.fixture(scope="session")
def kafka_bootstrap(network: Network) -> Iterator[str]:
    port = free_port()
    container = (
        DockerContainer(KAFKA_IMAGE)
        .with_network(network)
        .with_network_aliases("kafka")
        .with_bind_ports(9094, port)
        .with_envs(
            KAFKA_NODE_ID="1",
            KAFKA_PROCESS_ROLES="broker,controller",
            KAFKA_LISTENERS="PLAINTEXT://:9092,CONTROLLER://:9093,EXTERNAL://:9094",
            KAFKA_ADVERTISED_LISTENERS=f"PLAINTEXT://kafka:9092,EXTERNAL://127.0.0.1:{port}",
            KAFKA_LISTENER_SECURITY_PROTOCOL_MAP="PLAINTEXT:PLAINTEXT,CONTROLLER:PLAINTEXT,EXTERNAL:PLAINTEXT",
            KAFKA_CONTROLLER_LISTENER_NAMES="CONTROLLER",
            KAFKA_CONTROLLER_QUORUM_VOTERS="1@localhost:9093",
            KAFKA_INTER_BROKER_LISTENER_NAME="PLAINTEXT",
            KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR="1",
            KAFKA_TRANSACTION_STATE_LOG_REPLICATION_FACTOR="1",
            KAFKA_TRANSACTION_STATE_LOG_MIN_ISR="1",
            KAFKA_GROUP_INITIAL_REBALANCE_DELAY_MS="0",
            KAFKA_AUTO_CREATE_TOPICS_ENABLE="false",
        )
    )
    with container:
        bootstrap = f"127.0.0.1:{port}"
        admin = AdminClient({"bootstrap.servers": bootstrap})
        wait_until(lambda: admin.list_topics(timeout=2) is not None, 60, "kafka")
        yield bootstrap


@pytest.fixture(scope="session")
def mock() -> Iterator[MockServer]:
    server = MockServer()
    server.start()
    yield server
    server.stop()


@pytest.fixture(scope="session")
def app_url(
    request: pytest.FixtureRequest,
    network: Network,
    postgres: DockerContainer,
    kafka_bootstrap: str,
    mock: MockServer,
) -> Iterator[str]:
    config, topics = prepare_config(f"http://host.docker.internal:{mock.port}")

    admin = AdminClient({"bootstrap.servers": kafka_bootstrap})
    for future in admin.create_topics([NewTopic(t, num_partitions=1, replication_factor=1) for t in topics]).values():
        future.result()

    image = DockerImage(path=str(ROOT), tag=APP_IMAGE, clean_up=False)
    image.build()

    container = (
        DockerContainer(APP_IMAGE)
        .with_network(network)
        .with_network_aliases("loyalty")
        .with_exposed_ports(8080)
        .with_envs(**APP_ENV)
        .with_kwargs(extra_hosts={"host.docker.internal": "host-gateway"})
    )
    for name, content in config.items():
        container.with_copy_into_container(content, f"/etc/loyalty/{name}")
    with container:
        wrapped = container.get_wrapped_container()
        wrapped.reload()
        if wrapped.status != "running":
            raise RuntimeError("the service exited:\n" + logs(container))
        url = f"http://{container.get_container_host_ip()}:{container.get_exposed_port(8080)}"

        def healthy() -> bool:
            wrapped.reload()
            if wrapped.status != "running":
                raise RuntimeError("the service exited:\n" + logs(container))
            return httpx.get(f"{url}/healthz", timeout=2).status_code == 200

        try:
            wait_until(healthy, 120, "the service")
        except TimeoutError:
            raise TimeoutError("the service is not ready:\n" + logs(container)) from None

        yield url

        if request.session.testsfailed:
            capture = request.config.pluginmanager.getplugin("capturemanager")
            with capture.global_and_fixture_disabled():
                print("\n--- service logs ---\n" + logs(container), file=sys.stderr)


def logs(container: DockerContainer) -> str:
    stdout, stderr = container.get_logs()
    return (stdout + stderr).decode(errors="replace")


@pytest.fixture(scope="session")
def api(app_url: str) -> Iterator[httpx.Client]:
    with httpx.Client(base_url=app_url, timeout=10) as client:
        yield client


@pytest.fixture(scope="session")
def publish(kafka_bootstrap: str, app_url: str) -> Iterator[Callable[[str, int, dict[str, Any]], None]]:
    producer = Producer({"bootstrap.servers": kafka_bootstrap})

    def send(topic: str, key: int, event: dict[str, Any]) -> None:
        producer.produce(topic, key=str(key), value=json.dumps(event))
        assert producer.flush(10) == 0, f"event to {topic} is not delivered"

    yield send
    producer.flush(10)
