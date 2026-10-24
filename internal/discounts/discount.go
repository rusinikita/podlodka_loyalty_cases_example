// Package discounts owns user discounts: granting by a campaign or as
// a compensation, picking a discount for an order and redeeming it.
package discounts

import (
	"errors"
	"time"
)

var (
	ErrUnavailable       = errors.New("discount is used or expired")
	ErrCompensationLimit = errors.New("compensation is above the limits")
	ErrCommentRequired   = errors.New("comment with the ticket number is required")
)

// Discount is a row of user_discounts. Either Percent (with an optional
// MaxAmount cap) or Amount is set.
type Discount struct {
	ID          int64      `json:"id"`
	UserID      int64      `json:"-"`
	CampaignID  *int64     `json:"campaign_id"`
	Channel     *string    `json:"-"`
	PaymentID   *string    `json:"-"`
	Percent     *int       `json:"percent"`
	Amount      *int       `json:"amount"`
	MaxAmount   *int       `json:"max_amount"`
	ExpiresAt   time.Time  `json:"expires_at"`
	UsedAt      *time.Time `json:"used_at"`
	UsedOrderID *int64     `json:"-"`
	CreatedBy   *string    `json:"-"`
	Comment     *string    `json:"-"`
	CreatedAt   time.Time  `json:"created_at"`
}

// Value is the discount for an order of orderAmount.
func (d Discount) Value(orderAmount int) int {
	switch {
	case d.Percent != nil:
		v := orderAmount * *d.Percent / 100
		if d.MaxAmount != nil && v > *d.MaxAmount {
			v = *d.MaxAmount
		}
		return v
	case d.Amount != nil:
		return min(*d.Amount, orderAmount)
	}
	return 0
}

// GrantRequest is a discount granted by a campaign.
type GrantRequest struct {
	UserID     int64
	CampaignID int64
	Channel    string
	PaymentID  string
	Percent    int
	Amount     int
	MaxAmount  int
	ExpiresAt  time.Time
	// Notify sends the user a push about the discount for the next order.
	Notify bool
}

// CompensationRequest is a discount granted by an operator in the admin panel.
type CompensationRequest struct {
	UserID    int64
	Percent   int
	MaxAmount *int
	TTLDays   int
	Operator  string
	Comment   string
}

type QuoteRequest struct {
	UserID      int64
	OrderAmount int
	CityID      int
	OrderedAt   time.Time
}

type Quote struct {
	DiscountID *int64
	Amount     int
}

// OrderConditions are the campaign conditions checked when a discount is
// used for an order.
type OrderConditions struct {
	TimeFrom       string `json:"time_from"`
	TimeTo         string `json:"time_to"`
	MinOrderAmount int    `json:"min_order_amount"`
}

func (c OrderConditions) Match(orderAmount int, orderedAt time.Time) bool {
	if c.MinOrderAmount > 0 && orderAmount < c.MinOrderAmount {
		return false
	}
	if c.TimeFrom == "" || c.TimeTo == "" {
		return true
	}
	hm := orderedAt.Format("15:04")
	if c.TimeFrom <= c.TimeTo {
		return hm >= c.TimeFrom && hm < c.TimeTo
	}
	// The interval crosses midnight, e.g. 22:00–06:00.
	return hm >= c.TimeFrom || hm < c.TimeTo
}
