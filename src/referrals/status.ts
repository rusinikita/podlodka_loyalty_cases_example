/** State of a referral reward, stored in referral_rewards.status. */
export enum RewardStatus {
  Pending = 1,
  Paid,
  WaitingAntifraudResult,
  Rejected,
}

export function statusName(s: RewardStatus): string {
  switch (s) {
    case RewardStatus.Pending:
      return 'pending';
    case RewardStatus.Paid:
      return 'paid';
    case RewardStatus.WaitingAntifraudResult:
      return 'waiting_antifraud_result';
    case RewardStatus.Rejected:
      return 'rejected';
    default:
      return `RewardStatus(${s})`;
  }
}
