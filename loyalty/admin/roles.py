from collections.abc import Callable
from dataclasses import dataclass

from fastapi import Request

from loyalty.http import ApiError

# Roles of the admin panel, from the lowest. Each role can do everything the
# previous ones can (docs/admin_permissions.md).
ROLE_SUPPORT_L1 = "support_l1"
ROLE_SUPPORT_L2 = "support_l2"
ROLE_LOYALTY_OPERATOR = "loyalty_operator"
ROLE_LOYALTY_ADMIN = "loyalty_admin"

ROLE_RANK = {
    ROLE_SUPPORT_L1: 1,
    ROLE_SUPPORT_L2: 2,
    ROLE_LOYALTY_OPERATOR: 3,
    ROLE_LOYALTY_ADMIN: 4,
}


@dataclass(frozen=True)
class Operator:
    login: str
    role: str


def current_operator(request: Request) -> Operator:
    """The operator passed by the admin panel in headers."""
    op = Operator(
        login=request.headers.get("X-Operator-Login", ""),
        role=request.headers.get("X-Operator-Role", ""),
    )
    if not op.login or op.role not in ROLE_RANK:
        raise ApiError(403, "forbidden")
    return op


def require_role(min_role: str) -> Callable[[Request], None]:
    def check(request: Request) -> None:
        if ROLE_RANK.get(current_operator(request).role, 0) < ROLE_RANK[min_role]:
            raise ApiError(403, "forbidden")

    return check
