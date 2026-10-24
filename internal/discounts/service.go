package discounts

import (
	"context"
	"fmt"
	"log/slog"
	"strconv"
	"strings"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/clients/notifications"
	"git.shnyr.tech/loyalty/loyalty/internal/config"
)

const nextOrderPushTemplate = "loyalty_next_order_discount"

type Repository interface {
	Insert(ctx context.Context, d Discount) (Discount, error)
	Active(ctx context.Context, userID int64, now time.Time) ([]Discount, error)
	ByUser(ctx context.Context, userID, afterID int64, limit int) ([]Discount, error)
	Redeemable(ctx context.Context, userID int64, at time.Time) ([]Redeemable, error)
	MarkUsed(ctx context.Context, id, userID, orderID int64) (bool, error)
	CountByCampaign(ctx context.Context, campaignID int64) (int64, error)
	CountByUser(ctx context.Context, campaignID, userID int64) (int64, error)
	CountPromoUsedThisMonth(ctx context.Context, userID int64) (int64, error)
}

// Redeemable is an unused discount together with the order conditions of
// its campaign.
type Redeemable struct {
	Discount
	Conditions OrderConditions
}

type Notifier interface {
	Push(ctx context.Context, p notifications.Push) error
}

type Service struct {
	repo         Repository
	notify       Notifier
	compensation config.Compensation
	log          *slog.Logger
	now          func() time.Time
}

func NewService(repo Repository, notify Notifier, compensation config.Compensation, log *slog.Logger) *Service {
	return &Service{repo: repo, notify: notify, compensation: compensation, log: log, now: time.Now}
}

// Grant saves a discount of a campaign. With Notify the user also gets
// a push about the discount for the next order.
func (s *Service) Grant(ctx context.Context, req GrantRequest) (Discount, error) {
	d, err := s.repo.Insert(ctx, Discount{
		UserID:     req.UserID,
		CampaignID: &req.CampaignID,
		Channel:    nullString(req.Channel),
		PaymentID:  nullString(req.PaymentID),
		Percent:    nullInt(req.Percent),
		Amount:     nullInt(req.Amount),
		MaxAmount:  nullInt(req.MaxAmount),
		ExpiresAt:  req.ExpiresAt,
	})
	if err != nil {
		return Discount{}, fmt.Errorf("insert discount: %w", err)
	}

	if req.Notify {
		if err := s.pushNextOrder(ctx, d); err != nil {
			return d, fmt.Errorf("push discount %d: %w", d.ID, err)
		}
	}

	s.log.InfoContext(ctx, "discount granted",
		"discount_id", d.ID, "user_id", d.UserID, "campaign_id", req.CampaignID)
	return d, nil
}

func (s *Service) pushNextOrder(ctx context.Context, d Discount) error {
	params := map[string]string{"expires_at": d.ExpiresAt.Format(time.DateOnly)}
	if d.Percent != nil {
		params["percent"] = strconv.Itoa(*d.Percent)
	}
	if d.Amount != nil {
		params["amount"] = strconv.Itoa(*d.Amount)
	}
	return s.notify.Push(ctx, notifications.Push{
		UserID:   d.UserID,
		Template: nextOrderPushTemplate,
		Params:   params,
	})
}

// GrantCompensation saves a discount given by an operator. It has no
// campaign; the operator and the ticket number are kept in the row.
func (s *Service) GrantCompensation(ctx context.Context, req CompensationRequest) (Discount, error) {
	if req.Percent < 1 || req.Percent > s.compensation.MaxPercent ||
		req.TTLDays < 1 || req.TTLDays > s.compensation.TTLDays {
		return Discount{}, ErrCompensationLimit
	}
	if strings.TrimSpace(req.Comment) == "" {
		return Discount{}, ErrCommentRequired
	}

	d, err := s.repo.Insert(ctx, Discount{
		UserID:    req.UserID,
		Percent:   &req.Percent,
		MaxAmount: req.MaxAmount,
		ExpiresAt: s.now().AddDate(0, 0, req.TTLDays),
		CreatedBy: &req.Operator,
		Comment:   &req.Comment,
	})
	if err != nil {
		return Discount{}, fmt.Errorf("insert compensation: %w", err)
	}
	s.log.InfoContext(ctx, "compensation granted",
		"discount_id", d.ID, "user_id", d.UserID, "operator", req.Operator, "percent", req.Percent)
	return d, nil
}

func (s *Service) Active(ctx context.Context, userID int64) ([]Discount, error) {
	return s.repo.Active(ctx, userID, s.now())
}

func (s *Service) ByUser(ctx context.Context, userID, afterID int64, limit int) ([]Discount, error) {
	return s.repo.ByUser(ctx, userID, afterID, limit)
}

// Quote picks the user's discount for an order. Discounts do not stack:
// the order gets the single largest one whose order conditions match.
func (s *Service) Quote(ctx context.Context, req QuoteRequest) (Quote, error) {
	candidates, err := s.repo.Redeemable(ctx, req.UserID, req.OrderedAt)
	if err != nil {
		return Quote{}, fmt.Errorf("redeemable discounts: %w", err)
	}

	var best Quote
	for _, c := range candidates {
		if !c.Conditions.Match(req.OrderAmount, req.OrderedAt) {
			continue
		}
		if v := c.Value(req.OrderAmount); v > best.Amount {
			id := c.ID
			best = Quote{DiscountID: &id, Amount: v}
		}
	}
	return best, nil
}

func (s *Service) Redeem(ctx context.Context, discountID, userID, orderID int64) error {
	ok, err := s.repo.MarkUsed(ctx, discountID, userID, orderID)
	if err != nil {
		return fmt.Errorf("mark discount %d used: %w", discountID, err)
	}
	if !ok {
		return ErrUnavailable
	}
	return nil
}

func (s *Service) CountByCampaign(ctx context.Context, campaignID int64) (int64, error) {
	return s.repo.CountByCampaign(ctx, campaignID)
}

func (s *Service) CountByUser(ctx context.Context, campaignID, userID int64) (int64, error) {
	return s.repo.CountByUser(ctx, campaignID, userID)
}

func (s *Service) CountPromoUsedThisMonth(ctx context.Context, userID int64) (int64, error) {
	return s.repo.CountPromoUsedThisMonth(ctx, userID)
}

func nullString(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func nullInt(v int) *int {
	if v == 0 {
		return nil
	}
	return &v
}
