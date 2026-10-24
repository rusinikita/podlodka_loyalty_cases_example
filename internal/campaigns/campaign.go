// Package campaigns runs loyalty campaigns: on a trigger it picks the active
// campaigns, checks their grant conditions and grants the reward.
package campaigns

import (
	"errors"
	"time"
)

type Trigger string

const (
	TriggerPromoCode        Trigger = "promo_code"
	TriggerPaymentSucceeded Trigger = "payment_succeeded"
	TriggerUserRegistered   Trigger = "user_registered"
)

type RewardType string

const (
	RewardDiscount RewardType = "discount"
	RewardReferral RewardType = "referral_reward"
)

const (
	SegmentAll       = "all"
	RecipientInviter = "inviter"
)

var (
	ErrNotFound = errors.New("campaign not found")
	ErrConflict = errors.New("slug or promo code already exists")
	ErrInvalid  = errors.New("invalid campaign")

	ErrNotEligible          = errors.New("not eligible")
	ErrExpired              = errors.New("campaign expired")
	ErrAlreadyUsed          = errors.New("per-user limit reached")
	ErrActivationsExhausted = errors.New("campaign activations exhausted")
	ErrMonthlyLimit         = errors.New("monthly promo code limit reached")
)

type Campaign struct {
	ID           int64        `json:"id"`
	Slug         string       `json:"slug"`
	Trigger      Trigger      `json:"trigger"`
	PromoCode    *string      `json:"promo_code"`
	Conditions   Conditions   `json:"conditions"`
	RewardType   RewardType   `json:"reward_type"`
	RewardParams RewardParams `json:"reward_params"`
	StartsAt     time.Time    `json:"starts_at"`
	EndsAt       *time.Time   `json:"ends_at"`
	IsActive     bool         `json:"is_active"`
	CreatedBy    *string      `json:"created_by,omitempty"`
	CreatedAt    time.Time    `json:"created_at"`
}

// Conditions of a campaign. Grant conditions are checked here when the
// trigger fires; order conditions (time_from, time_to, min_order_amount)
// are checked by discounts when the discount is used.
type Conditions struct {
	Countries      []string `json:"countries,omitempty"`
	Segment        string   `json:"segment,omitempty"`
	CityIDs        []int    `json:"city_ids,omitempty"`
	PerUserLimit   int      `json:"per_user_limit,omitempty"`
	MaxActivations int      `json:"max_activations,omitempty"`
	SKUIDs         []int64  `json:"sku_ids,omitempty"`
	PaymentMethods []string `json:"payment_methods,omitempty"`
	Invited        bool     `json:"invited,omitempty"`
	TimeFrom       string   `json:"time_from,omitempty"`
	TimeTo         string   `json:"time_to,omitempty"`
	MinOrderAmount int      `json:"min_order_amount,omitempty"`
}

// RewardParams of a discount: Percent with an optional MaxAmount cap, or
// a fixed Amount; TTLDays, or until the end of the campaign if not set.
// Of a referral reward: Amount and Recipient.
type RewardParams struct {
	Percent   int    `json:"percent,omitempty"`
	Amount    int    `json:"amount,omitempty"`
	MaxAmount int    `json:"max_amount,omitempty"`
	TTLDays   int    `json:"ttl_days,omitempty"`
	Recipient string `json:"recipient,omitempty"`
}

// Event is what fired the trigger: a promo code entered in the app, a payment
// or a registration. Fields that the trigger does not carry are empty.
type Event struct {
	Trigger Trigger
	UserID  int64
	Country string
	CityID  int
	Segment string

	// promo_code
	Channel string

	// payment_succeeded
	PaymentID     string
	PaymentMethod string
	SKUIDs        []int64

	// user_registered; 0 if the user was not invited
	InvitedBy int64
}

// Ineligible tells which grant condition did not match. It wraps
// ErrNotEligible, ErrAlreadyUsed or ErrActivationsExhausted.
type Ineligible struct {
	Reason string
	Err    error
}

func (e *Ineligible) Error() string { return e.Err.Error() + ": " + e.Reason }
func (e *Ineligible) Unwrap() error { return e.Err }

func ineligible(reason string) *Ineligible {
	return &Ineligible{Reason: reason, Err: ErrNotEligible}
}
