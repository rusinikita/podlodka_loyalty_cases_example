// Package referrals owns rewards for invited friends: creation, statuses and
// payouts to the inviter's card through payout-gateway.
package referrals

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/clients/payoutgateway"
)

// Reward is a row of referral_rewards. UserID is the inviter who gets the
// money, InviteeID is the invited friend.
type Reward struct {
	ID              int64        `json:"id"`
	CampaignID      int64        `json:"-"`
	UserID          int64        `json:"-"`
	InviteeID       int64        `json:"invitee_id"`
	Amount          int          `json:"amount"`
	Status          RewardStatus `json:"status"`
	StatusChangedAt time.Time    `json:"status_changed_at"`
	PayoutID        *string      `json:"-"`
	CreatedAt       time.Time    `json:"created_at"`
}

type NewReward struct {
	CampaignID int64
	UserID     int64
	InviteeID  int64
	Amount     int
}

type Repository interface {
	// Insert saves the reward unless the invitee already has one in the
	// campaign; created is false in that case.
	Insert(ctx context.Context, r Reward) (created bool, err error)
	ByUser(ctx context.Context, userID, afterID int64, limit int) ([]Reward, error)
	CountByCampaign(ctx context.Context, campaignID int64) (int64, error)
	ByStatusAfter(ctx context.Context, status RewardStatus, afterID int64, limit int) ([]Reward, error)
	MarkPaid(ctx context.Context, id int64, payoutID string) error
}

type PayoutClient interface {
	Pay(ctx context.Context, req payoutgateway.PayoutRequest) (payoutgateway.Payout, error)
}

type Service struct {
	repo    Repository
	payouts PayoutClient
	log     *slog.Logger
}

func NewService(repo Repository, payouts PayoutClient, log *slog.Logger) *Service {
	return &Service{repo: repo, payouts: payouts, log: log}
}

// Create saves a reward for the inviter. The payout is done later by
// the reward-payouts worker.
func (s *Service) Create(ctx context.Context, nr NewReward) error {
	created, err := s.repo.Insert(ctx, Reward{
		CampaignID: nr.CampaignID,
		UserID:     nr.UserID,
		InviteeID:  nr.InviteeID,
		Amount:     nr.Amount,
		Status:     RewardPending,
	})
	if err != nil {
		return fmt.Errorf("insert reward: %w", err)
	}
	if !created {
		s.log.InfoContext(ctx, "referral reward already exists",
			"campaign_id", nr.CampaignID, "invitee_id", nr.InviteeID)
		return nil
	}
	s.log.InfoContext(ctx, "referral reward created",
		"campaign_id", nr.CampaignID, "user_id", nr.UserID, "invitee_id", nr.InviteeID, "amount", nr.Amount)
	return nil
}

func (s *Service) ByUser(ctx context.Context, userID, afterID int64, limit int) ([]Reward, error) {
	return s.repo.ByUser(ctx, userID, afterID, limit)
}

func (s *Service) CountByCampaign(ctx context.Context, campaignID int64) (int64, error) {
	return s.repo.CountByCampaign(ctx, campaignID)
}
