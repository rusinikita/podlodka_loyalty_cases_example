package referrals

import (
	"context"
	"fmt"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/clients/antifraud"
)

// antifraudVerdictTimeout is how long a reward waits for the antifraud
// verdict. After that it goes to payout anyway: fail open, honest users must
// not wait for antifraud longer than a day.
const antifraudVerdictTimeout = 24 * time.Hour

// CheckAntifraud asks antifraud for verdicts on rewards waiting for one,
// walking them by id in batches of batchSize. Approved rewards become
// pending and are paid by reward-payouts, rejected ones are closed. A reward
// still pending at antifraud after antifraudVerdictTimeout is approved.
func (s *Service) CheckAntifraud(ctx context.Context, batchSize int) error {
	batchSize = min(batchSize, antifraud.MaxBatch)

	var (
		lastID                                  int64
		approved, rejected, failedOpen, waiting int
	)
	for {
		batch, err := s.repo.ByStatusAfter(ctx, RewardWaitingAntifraudResult, lastID, batchSize)
		if err != nil {
			return fmt.Errorf("select rewards waiting for antifraud: %w", err)
		}
		if len(batch) == 0 {
			break
		}
		lastID = batch[len(batch)-1].ID

		verdicts, err := s.antifraud.Verdicts(ctx, rewardChecks(batch))
		if err != nil {
			return fmt.Errorf("antifraud verdicts for rewards %d..%d: %w", batch[0].ID, lastID, err)
		}

		now := s.now()
		for i, r := range batch {
			v := verdicts[i]
			if v.RewardID != r.ID {
				return fmt.Errorf("antifraud verdict for reward %d, expected %d", v.RewardID, r.ID)
			}

			switch {
			case v.Verdict == antifraud.VerdictApproved:
				err = s.repo.SetStatus(ctx, r.ID, RewardWaitingAntifraudResult, RewardPending)
				approved++
			case v.Verdict == antifraud.VerdictRejected:
				err = s.repo.SetStatus(ctx, r.ID, RewardWaitingAntifraudResult, RewardRejected)
				rejected++
				s.log.InfoContext(ctx, "reward rejected by antifraud",
					"reward_id", r.ID, "user_id", r.UserID, "reason", deref(v.Reason))
			case now.Sub(r.StatusChangedAt) >= antifraudVerdictTimeout:
				err = s.repo.SetStatus(ctx, r.ID, RewardWaitingAntifraudResult, RewardPending)
				failedOpen++
				s.log.WarnContext(ctx, "no antifraud verdict in time, reward released to payout",
					"reward_id", r.ID, "user_id", r.UserID, "waiting", now.Sub(r.StatusChangedAt).Round(time.Minute))
			default:
				waiting++
			}
			if err != nil {
				return fmt.Errorf("update reward %d: %w", r.ID, err)
			}
		}
	}

	s.log.InfoContext(ctx, "antifraud check done",
		"approved", approved, "rejected", rejected, "failed_open", failedOpen, "waiting", waiting)
	return nil
}

func rewardChecks(batch []Reward) []antifraud.RewardCheck {
	items := make([]antifraud.RewardCheck, len(batch))
	for i, r := range batch {
		items[i] = antifraud.RewardCheck{
			RewardID:  r.ID,
			InviterID: r.UserID,
			InviteeID: r.InviteeID,
			Amount:    r.Amount,
			Currency:  payoutCurrency,
			CreatedAt: r.CreatedAt,
		}
	}
	return items
}

func deref(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}
