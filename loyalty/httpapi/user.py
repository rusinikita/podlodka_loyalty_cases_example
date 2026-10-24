from dataclasses import dataclass

from fastapi import Request

from loyalty.http import bad_request


@dataclass(frozen=True)
class User:
    """The user context passed by the API gateway in headers."""

    id: int
    country: str
    city_id: int
    segment: str


def user_from_headers(request: Request) -> User:
    raw_id = request.headers.get("X-User-Id", "")
    if not raw_id.isdigit() or int(raw_id) <= 0:
        raise bad_request("invalid X-User-Id")
    city = request.headers.get("X-User-City-Id", "")
    if city and not city.isdigit():
        raise bad_request("invalid X-User-City-Id")
    return User(
        id=int(raw_id),
        country=request.headers.get("X-User-Country", ""),
        city_id=int(city) if city else 0,
        segment=request.headers.get("X-User-Segment", ""),
    )
