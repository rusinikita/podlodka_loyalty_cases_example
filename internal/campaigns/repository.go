package campaigns

import (
	"context"
	"errors"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"git.shnyr.tech/loyalty/loyalty/internal/postgres"
)

const campaignColumns = `id, slug, trigger, promo_code, conditions, reward_type, reward_params,
	starts_at, ends_at, is_active, created_by, created_at`

type PostgresRepository struct {
	db *pgxpool.Pool
}

func NewPostgresRepository(db *pgxpool.Pool) *PostgresRepository {
	return &PostgresRepository{db: db}
}

func (r *PostgresRepository) ActiveByTrigger(ctx context.Context, t Trigger, now time.Time) ([]Campaign, error) {
	rows, err := r.db.Query(ctx, `
		SELECT `+campaignColumns+`
		FROM campaigns
		WHERE trigger = $1
		  AND is_active
		  AND starts_at <= $2
		  AND (ends_at IS NULL OR ends_at > $2)
		ORDER BY id`,
		t, now)
	if err != nil {
		return nil, err
	}
	return collectCampaigns(rows)
}

func (r *PostgresRepository) ByPromoCode(ctx context.Context, code string) (Campaign, error) {
	row := r.db.QueryRow(ctx, `
		SELECT `+campaignColumns+`
		FROM campaigns
		WHERE promo_code = $1`,
		code)
	return scanOne(row)
}

func (r *PostgresRepository) ByID(ctx context.Context, id int64) (Campaign, error) {
	row := r.db.QueryRow(ctx, `SELECT `+campaignColumns+` FROM campaigns WHERE id = $1`, id)
	return scanOne(row)
}

func (r *PostgresRepository) Search(ctx context.Context, f SearchFilter) ([]Campaign, error) {
	rows, err := r.db.Query(ctx, `
		SELECT `+campaignColumns+`
		FROM campaigns
		WHERE ($1 = '' OR slug ILIKE '%' || $1 || '%' OR promo_code ILIKE '%' || $1 || '%')
		  AND ($2 = '' OR trigger = $2)
		  AND ($3::boolean IS NULL OR is_active = $3)
		  AND id > $4
		ORDER BY id
		LIMIT $5`,
		f.Query, string(f.Trigger), f.Active, f.AfterID, f.Limit)
	if err != nil {
		return nil, err
	}
	return collectCampaigns(rows)
}

func (r *PostgresRepository) Insert(ctx context.Context, c Campaign) (Campaign, error) {
	row := r.db.QueryRow(ctx, `
		INSERT INTO campaigns
			(slug, trigger, promo_code, conditions, reward_type, reward_params, starts_at, ends_at, is_active, created_by)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
		RETURNING `+campaignColumns,
		c.Slug, c.Trigger, c.PromoCode, c.Conditions, c.RewardType, c.RewardParams,
		c.StartsAt, c.EndsAt, c.IsActive, c.CreatedBy)
	created, err := scanOne(row)
	if postgres.IsUniqueViolation(err) {
		return Campaign{}, ErrConflict
	}
	return created, err
}

func (r *PostgresRepository) Update(ctx context.Context, c Campaign) (Campaign, error) {
	row := r.db.QueryRow(ctx, `
		UPDATE campaigns
		SET slug = $2, trigger = $3, promo_code = $4, conditions = $5, reward_type = $6,
		    reward_params = $7, starts_at = $8, ends_at = $9, is_active = $10
		WHERE id = $1
		RETURNING `+campaignColumns,
		c.ID, c.Slug, c.Trigger, c.PromoCode, c.Conditions, c.RewardType, c.RewardParams,
		c.StartsAt, c.EndsAt, c.IsActive)
	updated, err := scanOne(row)
	if postgres.IsUniqueViolation(err) {
		return Campaign{}, ErrConflict
	}
	return updated, err
}

func scanCampaign(row pgx.Row) (Campaign, error) {
	var c Campaign
	err := row.Scan(&c.ID, &c.Slug, &c.Trigger, &c.PromoCode, &c.Conditions, &c.RewardType,
		&c.RewardParams, &c.StartsAt, &c.EndsAt, &c.IsActive, &c.CreatedBy, &c.CreatedAt)
	return c, err
}

func scanOne(row pgx.Row) (Campaign, error) {
	c, err := scanCampaign(row)
	if errors.Is(err, pgx.ErrNoRows) {
		return Campaign{}, ErrNotFound
	}
	return c, err
}

func collectCampaigns(rows pgx.Rows) ([]Campaign, error) {
	defer rows.Close()
	var out []Campaign
	for rows.Next() {
		c, err := scanCampaign(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}
