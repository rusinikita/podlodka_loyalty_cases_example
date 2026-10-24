package campaigns

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/discounts"
	"git.shnyr.tech/loyalty/loyalty/internal/referrals"
)

type Repository interface {
	ActiveByTrigger(ctx context.Context, t Trigger, now time.Time) ([]Campaign, error)
	ByPromoCode(ctx context.Context, code string) (Campaign, error)
	ByID(ctx context.Context, id int64) (Campaign, error)
	Search(ctx context.Context, f SearchFilter) ([]Campaign, error)
	Insert(ctx context.Context, c Campaign) (Campaign, error)
	Update(ctx context.Context, c Campaign) (Campaign, error)
}

type Discounts interface {
	Grant(ctx context.Context, req discounts.GrantRequest) (discounts.Discount, error)
	CountByCampaign(ctx context.Context, campaignID int64) (int64, error)
	CountByUser(ctx context.Context, campaignID, userID int64) (int64, error)
	CountPromoUsedThisMonth(ctx context.Context, userID int64) (int64, error)
}

type Referrals interface {
	Create(ctx context.Context, nr referrals.NewReward) error
}

// OrderHistory counts the user's paid and completed orders across all
// channels and payment methods.
type OrderHistory interface {
	CountOrders(ctx context.Context, userID int64) (int, error)
}

type Service struct {
	repo             Repository
	discounts        Discounts
	referrals        Referrals
	orders           OrderHistory
	maxPromoPerMonth int
	log              *slog.Logger
	now              func() time.Time
}

func NewService(repo Repository, d Discounts, r Referrals, orders OrderHistory, maxPromoPerMonth int, log *slog.Logger) *Service {
	return &Service{
		repo:             repo,
		discounts:        d,
		referrals:        r,
		orders:           orders,
		maxPromoPerMonth: maxPromoPerMonth,
		log:              log,
		now:              time.Now,
	}
}

// Handle processes a payment or a registration: every active campaign of
// the trigger whose grant conditions match the event grants its reward.
func (s *Service) Handle(ctx context.Context, ev Event) error {
	active, err := s.repo.ActiveByTrigger(ctx, ev.Trigger, s.now())
	if err != nil {
		return fmt.Errorf("active campaigns: %w", err)
	}

	var matched []Campaign
	for _, c := range active {
		err := s.checkGrant(ctx, c, ev)
		var inel *Ineligible
		if errors.As(err, &inel) {
			s.log.DebugContext(ctx, "campaign skipped",
				"campaign", c.Slug, "user_id", ev.UserID, "reason", inel.Reason)
			continue
		}
		if err != nil {
			return fmt.Errorf("check campaign %s: %w", c.Slug, err)
		}
		matched = append(matched, c)
	}

	for _, c := range matched {
		if err := s.grant(ctx, c, ev); err != nil {
			return fmt.Errorf("grant campaign %s: %w", c.Slug, err)
		}
	}
	return nil
}

func (s *Service) ByPromoCode(ctx context.Context, code string) (Campaign, error) {
	return s.repo.ByPromoCode(ctx, code)
}

// ApplyPromoCode grants the discount of a promo code campaign to the user
// who entered the code.
func (s *Service) ApplyPromoCode(ctx context.Context, c Campaign, ev Event) (discounts.Discount, error) {
	now := s.now()
	if !c.IsActive || now.Before(c.StartsAt) {
		return discounts.Discount{}, ineligible("inactive")
	}
	if c.EndsAt != nil && !now.Before(*c.EndsAt) {
		return discounts.Discount{}, ErrExpired
	}
	if err := s.checkGrant(ctx, c, ev); err != nil {
		return discounts.Discount{}, err
	}

	used, err := s.discounts.CountPromoUsedThisMonth(ctx, ev.UserID)
	if err != nil {
		return discounts.Discount{}, fmt.Errorf("count promo codes this month: %w", err)
	}
	if used >= int64(s.maxPromoPerMonth) {
		return discounts.Discount{}, ErrMonthlyLimit
	}

	return s.grantDiscount(ctx, c, ev)
}

// checkGrant returns an *Ineligible error if a grant condition of c does not
// match the event.
func (s *Service) checkGrant(ctx context.Context, c Campaign, ev Event) error {
	cond := c.Conditions

	if len(cond.Countries) > 0 && !slices.Contains(cond.Countries, ev.Country) {
		return &Ineligible{Reason: "country", Err: ErrWrongCountry}
	}
	if cond.Segment != "" && cond.Segment != SegmentAll && cond.Segment != ev.Segment {
		return ineligible("segment")
	}
	if len(cond.CityIDs) > 0 && !slices.Contains(cond.CityIDs, ev.CityID) {
		return ineligible("city")
	}
	if len(cond.SKUIDs) > 0 && !slices.ContainsFunc(ev.SKUIDs, func(id int64) bool {
		return slices.Contains(cond.SKUIDs, id)
	}) {
		return ineligible("sku")
	}
	if len(cond.PaymentMethods) > 0 && !slices.Contains(cond.PaymentMethods, ev.PaymentMethod) {
		return ineligible("payment_method")
	}
	if cond.Invited && ev.InvitedBy == 0 {
		return ineligible("not_invited")
	}
	if cond.FirstOrder {
		first, err := s.isFirstOrder(ctx, ev.UserID)
		if err != nil {
			return err
		}
		if !first {
			return ineligible("not_first_order")
		}
	}

	if cond.PerUserLimit > 0 {
		n, err := s.discounts.CountByUser(ctx, c.ID, ev.UserID)
		if err != nil {
			return fmt.Errorf("count user discounts: %w", err)
		}
		if n >= int64(cond.PerUserLimit) {
			return &Ineligible{Reason: "per_user_limit", Err: ErrAlreadyUsed}
		}
	}
	if cond.MaxActivations > 0 {
		n, err := s.discounts.CountByCampaign(ctx, c.ID)
		if err != nil {
			return fmt.Errorf("count campaign discounts: %w", err)
		}
		if n >= int64(cond.MaxActivations) {
			return &Ineligible{Reason: "max_activations", Err: ErrActivationsExhausted}
		}
	}
	return nil
}

// isFirstOrder reports whether the payment is for the user's first order:
// the order history, which already includes the paid order, has exactly one.
func (s *Service) isFirstOrder(ctx context.Context, userID int64) (bool, error) {
	n, err := s.orders.CountOrders(ctx, userID)
	if err != nil {
		return false, fmt.Errorf("count user orders: %w", err)
	}
	return n == 1, nil
}

func (s *Service) grant(ctx context.Context, c Campaign, ev Event) error {
	switch c.RewardType {
	case RewardDiscount:
		_, err := s.grantDiscount(ctx, c, ev)
		return err
	case RewardReferral:
		return s.grantReferral(ctx, c, ev)
	default:
		s.log.WarnContext(ctx, "unknown reward type", "campaign", c.Slug, "reward_type", c.RewardType)
		return nil
	}
}

func (s *Service) grantDiscount(ctx context.Context, c Campaign, ev Event) (discounts.Discount, error) {
	p := c.RewardParams

	var expiresAt time.Time
	switch {
	case p.TTLDays > 0:
		expiresAt = s.now().AddDate(0, 0, p.TTLDays)
	case c.EndsAt != nil:
		expiresAt = *c.EndsAt
	default:
		return discounts.Discount{}, fmt.Errorf("campaign %s: neither ttl_days nor ends_at is set", c.Slug)
	}

	return s.discounts.Grant(ctx, discounts.GrantRequest{
		UserID:     ev.UserID,
		CampaignID: c.ID,
		Channel:    ev.Channel,
		PaymentID:  ev.PaymentID,
		Percent:    p.Percent,
		Amount:     p.Amount,
		MaxAmount:  p.MaxAmount,
		ExpiresAt:  expiresAt,
		Notify:     c.Trigger == TriggerPaymentSucceeded,
	})
}

func (s *Service) grantReferral(ctx context.Context, c Campaign, ev Event) error {
	if c.RewardParams.Recipient != RecipientInviter {
		s.log.WarnContext(ctx, "unsupported reward recipient",
			"campaign", c.Slug, "recipient", c.RewardParams.Recipient)
		return nil
	}
	return s.referrals.Create(ctx, referrals.NewReward{
		CampaignID: c.ID,
		UserID:     ev.InvitedBy,
		InviteeID:  ev.UserID,
		Amount:     c.RewardParams.Amount,
	})
}
