package referrals

import "fmt"

// RewardStatus is the state of a referral reward, stored in
// referral_rewards.status.
type RewardStatus int16

const (
	RewardPending RewardStatus = iota + 1
	RewardPaid
)

func (s RewardStatus) String() string {
	switch s {
	case RewardPending:
		return "pending"
	case RewardPaid:
		return "paid"
	default:
		return fmt.Sprintf("RewardStatus(%d)", int16(s))
	}
}

func (s RewardStatus) MarshalText() ([]byte, error) {
	return []byte(s.String()), nil
}
