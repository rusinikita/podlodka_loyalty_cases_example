"""Error handling and pagination shared by the HTTP routers."""

from typing import Any

import structlog
from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

log = structlog.get_logger()


class ApiError(Exception):
    """An API error: code is the machine-readable error from the spec, message is optional."""

    def __init__(self, status: int, code: str, message: str = "") -> None:
        super().__init__(message or code)
        self.status = status
        self.code = code
        self.message = message


def bad_request(message: str) -> ApiError:
    return ApiError(400, "bad_request", message)


def error_response(status: int, code: str, message: str = "") -> JSONResponse:
    body = {"error": code}
    if message:
        body["message"] = message
    return JSONResponse(body, status_code=status)


def install_error_handlers(app: FastAPI) -> None:
    @app.exception_handler(ApiError)
    async def api_error(_: Request, e: ApiError) -> JSONResponse:
        return error_response(e.status, e.code, e.message)

    @app.exception_handler(RequestValidationError)
    async def validation_error(_: Request, e: RequestValidationError) -> JSONResponse:
        first = e.errors()[0] if e.errors() else {}
        where = ".".join(str(p) for p in first.get("loc", ())[1:])
        return error_response(400, "bad_request", f"{where}: {first.get('msg', 'invalid request')}")

    @app.exception_handler(Exception)
    async def internal(request: Request, e: Exception) -> JSONResponse:
        log.error("request failed", method=request.method, path=request.url.path, exc_info=e)
        return error_response(500, "internal")


def parse_cursor(value: str | None) -> int:
    """The cursor query parameter: the id of the last item of the previous page,
    0 for the first page."""
    if not value:
        return 0
    if not value.isdigit():
        raise bad_request("invalid cursor")
    return int(value)


def next_cursor(items: list[Any], limit: int) -> str | None:
    """The cursor of the next page, or None if the page is not full."""
    if not items or len(items) < limit:
        return None
    return str(items[-1].id)
