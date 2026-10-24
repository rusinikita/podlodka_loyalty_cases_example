// Package promo applies promo codes entered by users: it finds the campaign
// of the code and passes it to campaigns.
package promo

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strings"

	"git.shnyr.tech/loyalty/loyalty/internal/campaigns"
	"git.shnyr.tech/loyalty/loyalty/internal/discounts"
)

const (
	ChannelDeeplink = "deeplink"
	ChannelManual   = "manual"
)

var (
	ErrPromoNotFound     = errors.New("promo not found")
	ErrPromoExpired      = errors.New("promo expired")
	ErrPromoAlreadyUsed  = errors.New("promo already used")
	ErrPromoLimitReached = errors.New("promo activation limit reached")
	ErrMonthlyLimit      = errors.New("monthly promo limit reached")
	ErrPromoWrongCountry = errors.New("promo not available in the user's country")
)

type Campaigns interface {
	ByPromoCode(ctx context.Context, code string) (campaigns.Campaign, error)
	ApplyPromoCode(ctx context.Context, c campaigns.Campaign, ev campaigns.Event) (discounts.Discount, error)
}

type ApplyRequest struct {
	UserID  int64
	Country string
	CityID  int
	Segment string
	Code    string
	Channel string
}

type Service struct {
	campaigns Campaigns
	log       *slog.Logger
}

func NewService(c Campaigns, log *slog.Logger) *Service {
	return &Service{campaigns: c, log: log}
}

func normalizeCode(raw string) string {
	return strings.ToUpper(strings.TrimSpace(raw))
}

// Apply activates the promo code for the user: the user gets the discount
// of the code's campaign for the next order.
func (s *Service) Apply(ctx context.Context, req ApplyRequest) (discounts.Discount, error) {
	c, err := s.campaigns.ByPromoCode(ctx, normalizeCode(req.Code))
	if errors.Is(err, campaigns.ErrNotFound) {
		s.log.InfoContext(ctx, "promo not found", "input", req.Code, "user_id", req.UserID, "channel", req.Channel)
		return discounts.Discount{}, ErrPromoNotFound
	}
	if err != nil {
		return discounts.Discount{}, fmt.Errorf("get campaign by promo code: %w", err)
	}

	d, err := s.campaigns.ApplyPromoCode(ctx, c, campaigns.Event{
		Trigger: campaigns.TriggerPromoCode,
		UserID:  req.UserID,
		Country: req.Country,
		CityID:  req.CityID,
		Segment: req.Segment,
		Channel: req.Channel,
	})

	var inel *campaigns.Ineligible
	switch {
	case err == nil:
		s.log.InfoContext(ctx, "promo applied",
			"campaign_id", c.ID, "user_id", req.UserID, "channel", req.Channel, "discount_id", d.ID)
		return d, nil
	case errors.Is(err, campaigns.ErrNotEligible) && errors.As(err, &inel):
		return discounts.Discount{}, s.hide(ctx, c, req, inel.Reason)
	case errors.Is(err, campaigns.ErrExpired):
		return discounts.Discount{}, ErrPromoExpired
	case errors.Is(err, campaigns.ErrAlreadyUsed):
		return discounts.Discount{}, ErrPromoAlreadyUsed
	case errors.Is(err, campaigns.ErrActivationsExhausted):
		return discounts.Discount{}, ErrPromoLimitReached
	case errors.Is(err, campaigns.ErrMonthlyLimit):
		return discounts.Discount{}, ErrMonthlyLimit
	case errors.Is(err, campaigns.ErrWrongCountry):
		return discounts.Discount{}, ErrPromoWrongCountry
	default:
		return discounts.Discount{}, fmt.Errorf("apply promo campaign %d: %w", c.ID, err)
	}
}

// hide makes a promo code the user is not eligible for look non-existent:
// targeted and not yet launched campaigns must not leak to everyone else.
func (s *Service) hide(ctx context.Context, c campaigns.Campaign, req ApplyRequest, reason string) error {
	s.log.InfoContext(ctx, "promo hidden",
		"campaign_id", c.ID, "user_id", req.UserID, "channel", req.Channel, "reason", reason)
	return ErrPromoNotFound
}
