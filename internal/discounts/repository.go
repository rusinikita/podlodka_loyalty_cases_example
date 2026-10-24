package discounts

import (
	"context"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

const discountColumns = `d.id, d.user_id, d.campaign_id, d.channel, d.payment_id, d.percent, d.amount,
	d.max_amount, d.expires_at, d.used_at, d.used_order_id, d.created_by, d.comment, d.created_at`

type PostgresRepository struct {
	db *pgxpool.Pool
}

func NewPostgresRepository(db *pgxpool.Pool) *PostgresRepository {
	return &PostgresRepository{db: db}
}

func (r *PostgresRepository) Insert(ctx context.Context, d Discount) (Discount, error) {
	row := r.db.QueryRow(ctx, `
		INSERT INTO user_discounts AS d
			(user_id, campaign_id, channel, payment_id, percent, amount, max_amount, expires_at, created_by, comment)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
		RETURNING `+discountColumns,
		d.UserID, d.CampaignID, d.Channel, d.PaymentID, d.Percent, d.Amount, d.MaxAmount,
		d.ExpiresAt, d.CreatedBy, d.Comment)
	return scanDiscount(row)
}

func (r *PostgresRepository) Active(ctx context.Context, userID int64, now time.Time) ([]Discount, error) {
	rows, err := r.db.Query(ctx, `
		SELECT `+discountColumns+`
		FROM user_discounts d
		WHERE d.user_id = $1 AND d.used_at IS NULL AND d.expires_at > $2
		ORDER BY d.expires_at`,
		userID, now)
	if err != nil {
		return nil, err
	}
	return collectDiscounts(rows)
}

func (r *PostgresRepository) ByUser(ctx context.Context, userID, afterID int64, limit int) ([]Discount, error) {
	rows, err := r.db.Query(ctx, `
		SELECT `+discountColumns+`
		FROM user_discounts d
		WHERE d.user_id = $1 AND ($2::bigint = 0 OR d.id < $2)
		ORDER BY d.id DESC
		LIMIT $3`,
		userID, afterID, limit)
	if err != nil {
		return nil, err
	}
	return collectDiscounts(rows)
}

func (r *PostgresRepository) Redeemable(ctx context.Context, userID int64, at time.Time) ([]Redeemable, error) {
	rows, err := r.db.Query(ctx, `
		SELECT `+discountColumns+`, coalesce(c.conditions, '{}')
		FROM user_discounts d
		LEFT JOIN campaigns c ON c.id = d.campaign_id
		WHERE d.user_id = $1 AND d.used_at IS NULL AND d.expires_at > $2`,
		userID, at)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Redeemable
	for rows.Next() {
		var x Redeemable
		if err := rows.Scan(discountFields(&x.Discount, &x.Conditions)...); err != nil {
			return nil, err
		}
		out = append(out, x)
	}
	return out, rows.Err()
}

func (r *PostgresRepository) MarkUsed(ctx context.Context, id, userID, orderID int64) (bool, error) {
	tag, err := r.db.Exec(ctx, `
		UPDATE user_discounts
		SET used_at = now(), used_order_id = $3
		WHERE id = $1 AND user_id = $2 AND used_at IS NULL AND expires_at > now()`,
		id, userID, orderID)
	if err != nil {
		return false, err
	}
	return tag.RowsAffected() == 1, nil
}

func (r *PostgresRepository) CountByCampaign(ctx context.Context, campaignID int64) (int64, error) {
	var n int64
	err := r.db.QueryRow(ctx, `SELECT count(*) FROM user_discounts WHERE campaign_id = $1`, campaignID).Scan(&n)
	return n, err
}

func (r *PostgresRepository) CountByUser(ctx context.Context, campaignID, userID int64) (int64, error) {
	var n int64
	err := r.db.QueryRow(ctx, `
		SELECT count(*) FROM user_discounts WHERE campaign_id = $1 AND user_id = $2`,
		campaignID, userID).Scan(&n)
	return n, err
}

func (r *PostgresRepository) CountPromoUsedThisMonth(ctx context.Context, userID int64) (int64, error) {
	var n int64
	err := r.db.QueryRow(ctx, `
		SELECT count(*)
		FROM user_discounts d
		JOIN campaigns c ON c.id = d.campaign_id
		WHERE d.user_id = $1
		  AND c.trigger = 'promo_code'
		  AND d.used_at >= date_trunc('month', now())`,
		userID).Scan(&n)
	return n, err
}

func discountFields(d *Discount, extra ...any) []any {
	fields := []any{
		&d.ID, &d.UserID, &d.CampaignID, &d.Channel, &d.PaymentID, &d.Percent, &d.Amount,
		&d.MaxAmount, &d.ExpiresAt, &d.UsedAt, &d.UsedOrderID, &d.CreatedBy, &d.Comment, &d.CreatedAt,
	}
	return append(fields, extra...)
}

func scanDiscount(row pgx.Row) (Discount, error) {
	var d Discount
	err := row.Scan(discountFields(&d)...)
	return d, err
}

func collectDiscounts(rows pgx.Rows) ([]Discount, error) {
	defer rows.Close()
	var out []Discount
	for rows.Next() {
		var d Discount
		if err := rows.Scan(discountFields(&d)...); err != nil {
			return nil, err
		}
		out = append(out, d)
	}
	return out, rows.Err()
}
