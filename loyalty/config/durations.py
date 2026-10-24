import re
from datetime import timedelta
from typing import Annotated

from pydantic import BeforeValidator

_UNITS = {"ms": 0.001, "s": 1, "m": 60, "h": 3600}
_PART = re.compile(r"(\d+(?:\.\d+)?)(ms|s|m|h)")


def parse_duration(value: object) -> timedelta:
    """Parses a duration written as in the config files: 500ms, 2s, 5m, 1h30m."""
    if isinstance(value, timedelta):
        return value
    s = str(value).strip()
    parts = _PART.findall(s)
    if not s or "".join(n + u for n, u in parts) != s:
        raise ValueError(f"invalid duration {s!r}")
    return timedelta(seconds=sum(float(n) * _UNITS[u] for n, u in parts))


Duration = Annotated[timedelta, BeforeValidator(parse_duration)]
