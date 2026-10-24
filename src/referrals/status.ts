/** State of a referral reward, stored in referral_rewards.status. */
export enum RewardStatus {
  Pending = 1,
  Paid,
}

export function statusName(s: RewardStatus): string {
  switch (s) {
    case RewardStatus.Pending:
      return 'pending';
    case RewardStatus.Paid:
      return 'paid';
    default:
      return `RewardStatus(${s})`;
  }
}
