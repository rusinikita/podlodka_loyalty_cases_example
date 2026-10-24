package referrals

import (
	"context"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

const rewardColumns = `id, campaign_id, user_id, invitee_id, amount, status, status_changed_at,
	payout_id::text, created_at`

type PostgresRepository struct {
	db *pgxpool.Pool
}

func NewPostgresRepository(db *pgxpool.Pool) *PostgresRepository {
	return &PostgresRepository{db: db}
}

func (r *PostgresRepository) Insert(ctx context.Context, rw Reward) (bool, error) {
	tag, err := r.db.Exec(ctx, `
		INSERT INTO referral_rewards (campaign_id, user_id, invitee_id, amount, status)
		VALUES ($1, $2, $3, $4, $5)
		ON CONFLICT (campaign_id, invitee_id) DO NOTHING`,
		rw.CampaignID, rw.UserID, rw.InviteeID, rw.Amount, rw.Status)
	if err != nil {
		return false, err
	}
	return tag.RowsAffected() == 1, nil
}

func (r *PostgresRepository) ByUser(ctx context.Context, userID, afterID int64, limit int) ([]Reward, error) {
	rows, err := r.db.Query(ctx, `
		SELECT `+rewardColumns+`
		FROM referral_rewards
		WHERE user_id = $1 AND ($2::bigint = 0 OR id < $2)
		ORDER BY id DESC
		LIMIT $3`,
		userID, afterID, limit)
	if err != nil {
		return nil, err
	}
	return collectRewards(rows)
}

func (r *PostgresRepository) CountByCampaign(ctx context.Context, campaignID int64) (int64, error) {
	var n int64
	err := r.db.QueryRow(ctx, `SELECT count(*) FROM referral_rewards WHERE campaign_id = $1`, campaignID).Scan(&n)
	return n, err
}

func (r *PostgresRepository) ByStatusAfter(ctx context.Context, status RewardStatus, afterID int64, limit int) ([]Reward, error) {
	rows, err := r.db.Query(ctx, `
		SELECT `+rewardColumns+`
		FROM referral_rewards
		WHERE status = $1 AND id > $2
		ORDER BY id
		LIMIT $3`,
		status, afterID, limit)
	if err != nil {
		return nil, err
	}
	return collectRewards(rows)
}

func (r *PostgresRepository) MarkPaid(ctx context.Context, id int64, payoutID string) error {
	_, err := r.db.Exec(ctx, `
		UPDATE referral_rewards
		SET status = $2, status_changed_at = now(), payout_id = $3::uuid
		WHERE id = $1 AND status = $4`,
		id, RewardPaid, payoutID, RewardPending)
	return err
}

func collectRewards(rows pgx.Rows) ([]Reward, error) {
	defer rows.Close()
	var out []Reward
	for rows.Next() {
		var rw Reward
		err := rows.Scan(&rw.ID, &rw.CampaignID, &rw.UserID, &rw.InviteeID, &rw.Amount,
			&rw.Status, &rw.StatusChangedAt, &rw.PayoutID, &rw.CreatedAt)
		if err != nil {
			return nil, err
		}
		out = append(out, rw)
	}
	return out, rows.Err()
}
