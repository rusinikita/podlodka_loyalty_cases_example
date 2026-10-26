from enum import IntEnum, auto


class RewardStatus(IntEnum):
    """State of a referral reward, stored in referral_rewards.status."""

    PENDING = auto()
    PAID = auto()
    WAITING_ANTIFRAUD_RESULT = auto()
    REJECTED = auto()


def status_name(value: int) -> str:
    try:
        return RewardStatus(value).name.lower()
    except ValueError:
        return f"RewardStatus({value})"
