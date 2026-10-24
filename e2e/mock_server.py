"""HTTP mock of the services loyalty calls: payout-gateway, notifications,
antifraud and order-history (see api/). Every request is recorded so tests can
check what the service sent."""

import json
import re
import threading
import uuid
from dataclasses import dataclass
from datetime import UTC, datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

_ORDER_COUNT = re.compile(r"^/v1/users/(\d+)/orders/count$")


@dataclass(frozen=True)
class Request:
    method: str
    path: str
    headers: dict[str, str]
    body: Any


class MockServer:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._requests: list[Request] = []
        self._server = ThreadingHTTPServer(("0.0.0.0", 0), self._handler())
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)

    @property
    def port(self) -> int:
        return self._server.server_address[1]

    def start(self) -> None:
        self._thread.start()

    def stop(self) -> None:
        self._server.shutdown()
        self._server.server_close()

    def requests(self, method: str, path: str) -> list[Request]:
        with self._lock:
            return [r for r in self._requests if r.method == method and r.path == path]

    def _record(self, r: Request) -> None:
        with self._lock:
            self._requests.append(r)

    def _handler(self) -> type[BaseHTTPRequestHandler]:
        mock = self

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self) -> None:
                self._serve("GET")

            def do_POST(self) -> None:
                self._serve("POST")

            def _serve(self, method: str) -> None:
                path = self.path.split("?", 1)[0]
                raw = self._read_body()
                body = json.loads(raw) if raw else None
                mock._record(Request(method, path, dict(self.headers.items()), body))

                status, payload = route(method, path, body)
                data = json.dumps(payload).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

            def _read_body(self) -> bytes:
                if self.headers.get("Transfer-Encoding", "").lower() != "chunked":
                    return self.rfile.read(int(self.headers.get("Content-Length") or 0))
                chunks = []
                while size := int(self.rfile.readline().split(b";", 1)[0].strip(), 16):
                    chunks.append(self.rfile.read(size))
                    self.rfile.readline()
                self.rfile.readline()
                return b"".join(chunks)

            def log_message(self, format: str, *args: Any) -> None:
                pass

        return Handler


def route(method: str, path: str, body: Any) -> tuple[int, Any]:
    now = datetime.now(UTC).isoformat().replace("+00:00", "Z")

    if method == "POST" and path == "/v1/payouts":
        payout_id = uuid.uuid5(uuid.NAMESPACE_URL, f"payout:{body['idempotency_key']}")
        return 200, {
            "payout_id": str(payout_id),
            "status": "accepted",
            "card_mask": "220220******4417",
            "created_at": now,
        }

    if method == "POST" and path == "/v1/push":
        return 202, {"push_id": str(uuid.uuid4())}

    if method == "POST" and path == "/v1/referral-rewards/verdicts":
        items = [{"reward_id": it["reward_id"], "verdict": "approved", "checked_at": now} for it in body["items"]]
        return 200, {"items": items}

    if method == "GET" and (m := _ORDER_COUNT.match(path)):
        return 200, {"user_id": int(m.group(1)), "count": 1, "as_of": now}

    return 404, {"code": "not_found", "message": f"{method} {path}"}
