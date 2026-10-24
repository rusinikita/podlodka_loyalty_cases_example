package referrals

import (
	"context"
	"errors"
	"fmt"
	"strconv"

	"git.shnyr.tech/loyalty/loyalty/internal/clients/payoutgateway"
)

const (
	payoutCurrency = "RUB"
	payoutPurpose  = "referral_reward"
)

// PayPending pays out pending rewards, walking them by id in batches of
// batchSize. A reward whose owner has no card stays pending and is tried
// again on the next run.
func (s *Service) PayPending(ctx context.Context, batchSize int) error {
	var (
		lastID                 int64
		paid, noMethod, failed int
	)
	for {
		batch, err := s.repo.ByStatusAfter(ctx, RewardPending, lastID, batchSize)
		if err != nil {
			return fmt.Errorf("select pending rewards: %w", err)
		}
		if len(batch) == 0 {
			break
		}

		for _, r := range batch {
			lastID = r.ID
			err := s.payOut(ctx, r)
			switch {
			case err == nil:
				paid++
			case errors.Is(err, payoutgateway.ErrNoPayoutMethod):
				noMethod++
				s.log.InfoContext(ctx, "reward not paid", "reward_id", r.ID, "user_id", r.UserID, "reason", "no_payout_method")
			default:
				if ctx.Err() != nil {
					return ctx.Err()
				}
				failed++
				s.log.WarnContext(ctx, "reward payout failed", "reward_id", r.ID, "user_id", r.UserID, "err", err)
			}
		}
	}

	s.log.InfoContext(ctx, "reward payouts done", "paid", paid, "no_payout_method", noMethod, "failed", failed)
	return nil
}

func (s *Service) payOut(ctx context.Context, r Reward) error {
	p, err := s.payouts.Pay(ctx, payoutgateway.PayoutRequest{
		IdempotencyKey: strconv.FormatInt(r.ID, 10),
		UserID:         r.UserID,
		Amount:         r.Amount,
		Currency:       payoutCurrency,
		Purpose:        payoutPurpose,
	})
	if err != nil {
		return err
	}
	if err := s.repo.MarkPaid(ctx, r.ID, p.PayoutID); err != nil {
		return fmt.Errorf("mark reward %d paid: %w", r.ID, err)
	}
	return nil
}
