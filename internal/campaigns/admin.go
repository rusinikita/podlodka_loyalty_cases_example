package campaigns

import (
	"context"
	"fmt"
	"time"
)

type SearchFilter struct {
	Query   string
	Trigger Trigger
	Active  *bool
	AfterID int64
	Limit   int
}

// Input is a campaign from the admin panel. On update only the set fields
// are changed.
type Input struct {
	Slug         *string       `json:"slug"`
	Trigger      *Trigger      `json:"trigger"`
	PromoCode    *string       `json:"promo_code"`
	Conditions   *Conditions   `json:"conditions"`
	RewardType   *RewardType   `json:"reward_type"`
	RewardParams *RewardParams `json:"reward_params"`
	StartsAt     *time.Time    `json:"starts_at"`
	EndsAt       *time.Time    `json:"ends_at"`
	IsActive     *bool         `json:"is_active"`
}

func (s *Service) Get(ctx context.Context, id int64) (Campaign, error) {
	return s.repo.ByID(ctx, id)
}

func (s *Service) Search(ctx context.Context, f SearchFilter) ([]Campaign, error) {
	return s.repo.Search(ctx, f)
}

func (s *Service) Create(ctx context.Context, in Input, operator string) (Campaign, error) {
	c := Campaign{IsActive: true, CreatedBy: &operator}
	in.apply(&c)
	if err := validate(c); err != nil {
		return Campaign{}, err
	}
	created, err := s.repo.Insert(ctx, c)
	if err != nil {
		return Campaign{}, err
	}
	s.log.InfoContext(ctx, "campaign created", "campaign_id", created.ID, "slug", created.Slug, "operator", operator)
	return created, nil
}

func (s *Service) Update(ctx context.Context, id int64, in Input, operator string) (Campaign, error) {
	c, err := s.repo.ByID(ctx, id)
	if err != nil {
		return Campaign{}, err
	}
	in.apply(&c)
	if err := validate(c); err != nil {
		return Campaign{}, err
	}
	updated, err := s.repo.Update(ctx, c)
	if err != nil {
		return Campaign{}, err
	}
	s.log.InfoContext(ctx, "campaign updated", "campaign_id", id, "operator", operator)
	return updated, nil
}

func (in Input) apply(c *Campaign) {
	if in.Slug != nil {
		c.Slug = *in.Slug
	}
	if in.Trigger != nil {
		c.Trigger = *in.Trigger
	}
	if in.PromoCode != nil {
		c.PromoCode = in.PromoCode
	}
	if in.Conditions != nil {
		c.Conditions = *in.Conditions
	}
	if in.RewardType != nil {
		c.RewardType = *in.RewardType
	}
	if in.RewardParams != nil {
		c.RewardParams = *in.RewardParams
	}
	if in.StartsAt != nil {
		c.StartsAt = *in.StartsAt
	}
	if in.EndsAt != nil {
		c.EndsAt = in.EndsAt
	}
	if in.IsActive != nil {
		c.IsActive = *in.IsActive
	}
}

func validate(c Campaign) error {
	if c.Slug == "" {
		return fmt.Errorf("%w: slug is required", ErrInvalid)
	}
	switch c.Trigger {
	case TriggerPromoCode:
		if c.PromoCode == nil || *c.PromoCode == "" {
			return fmt.Errorf("%w: promo_code is required for trigger %s", ErrInvalid, c.Trigger)
		}
	case TriggerPaymentSucceeded, TriggerUserRegistered:
	default:
		return fmt.Errorf("%w: unknown trigger %q", ErrInvalid, c.Trigger)
	}
	switch c.RewardType {
	case RewardDiscount:
		if c.RewardParams.Percent == 0 && c.RewardParams.Amount == 0 {
			return fmt.Errorf("%w: percent or amount is required", ErrInvalid)
		}
	case RewardReferral:
		if c.RewardParams.Amount == 0 {
			return fmt.Errorf("%w: amount is required", ErrInvalid)
		}
	default:
		return fmt.Errorf("%w: unknown reward type %q", ErrInvalid, c.RewardType)
	}
	if c.StartsAt.IsZero() {
		return fmt.Errorf("%w: starts_at is required", ErrInvalid)
	}
	return nil
}
