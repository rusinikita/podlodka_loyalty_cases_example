package campaigns

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"testing"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/discounts"
)

var testNow = time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)

type fakeRepo struct {
	Repository
	active []Campaign
}

func (f *fakeRepo) ActiveByTrigger(_ context.Context, t Trigger, _ time.Time) ([]Campaign, error) {
	var out []Campaign
	for _, c := range f.active {
		if c.Trigger == t {
			out = append(out, c)
		}
	}
	return out, nil
}

type fakeDiscounts struct {
	granted []discounts.GrantRequest
}

func (f *fakeDiscounts) Grant(_ context.Context, req discounts.GrantRequest) (discounts.Discount, error) {
	f.granted = append(f.granted, req)
	return discounts.Discount{ID: int64(len(f.granted)), UserID: req.UserID}, nil
}

func (f *fakeDiscounts) CountByCampaign(context.Context, int64) (int64, error)         { return 0, nil }
func (f *fakeDiscounts) CountByUser(context.Context, int64, int64) (int64, error)      { return 0, nil }
func (f *fakeDiscounts) CountPromoUsedThisMonth(context.Context, int64) (int64, error) { return 0, nil }

type fakeOrders struct {
	counts map[int64]int
	err    error
	calls  int
}

func (f *fakeOrders) CountOrders(_ context.Context, userID int64) (int, error) {
	f.calls++
	if f.err != nil {
		return 0, f.err
	}
	return f.counts[userID], nil
}

func newTestService(active ...Campaign) (*Service, *fakeDiscounts, *fakeOrders) {
	d := &fakeDiscounts{}
	o := &fakeOrders{counts: map[int64]int{}}
	s := NewService(&fakeRepo{active: active}, d, nil, o, 2, slog.New(slog.NewTextHandler(io.Discard, nil)))
	s.now = func() time.Time { return testNow }
	return s, d, o
}

func paymentCampaign(id int64, slug string, cond Conditions) Campaign {
	return Campaign{
		ID:           id,
		Slug:         slug,
		Trigger:      TriggerPaymentSucceeded,
		Conditions:   cond,
		RewardType:   RewardDiscount,
		RewardParams: RewardParams{Percent: 20, TTLDays: 14},
		StartsAt:     testNow.AddDate(0, 0, -1),
		IsActive:     true,
	}
}

func payment(userID int64) Event {
	return Event{
		Trigger:       TriggerPaymentSucceeded,
		UserID:        userID,
		Country:       "RU",
		PaymentID:     "pay-1",
		PaymentMethod: "card",
		SKUIDs:        []int64{100},
	}
}

func TestHandleFirstOrder(t *testing.T) {
	firstOrder := paymentCampaign(1, "first_order", Conditions{Countries: []string{"RU"}, FirstOrder: true})

	tests := []struct {
		name   string
		orders int
		want   int
	}{
		{"first order", 1, 1},
		{"second order", 2, 0},
		{"regular customer", 37, 0},
		{"no orders in history", 0, 0},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			s, d, o := newTestService(firstOrder)
			o.counts[42] = tt.orders

			if err := s.Handle(context.Background(), payment(42)); err != nil {
				t.Fatalf("Handle() error = %v", err)
			}
			if len(d.granted) != tt.want {
				t.Fatalf("granted %d discounts, want %d", len(d.granted), tt.want)
			}
			if tt.want == 1 {
				g := d.granted[0]
				if g.CampaignID != 1 || g.Percent != 20 || !g.ExpiresAt.Equal(testNow.AddDate(0, 0, 14)) || !g.Notify {
					t.Errorf("grant = %+v", g)
				}
			}
		})
	}
}

func TestHandleFirstOrderOtherCountry(t *testing.T) {
	firstOrder := paymentCampaign(1, "first_order", Conditions{Countries: []string{"RU"}, FirstOrder: true})
	s, d, o := newTestService(firstOrder)
	o.counts[42] = 1

	ev := payment(42)
	ev.Country = "KZ"
	if err := s.Handle(context.Background(), ev); err != nil {
		t.Fatalf("Handle() error = %v", err)
	}
	if len(d.granted) != 0 {
		t.Errorf("granted %d discounts, want 0", len(d.granted))
	}
	if o.calls != 0 {
		t.Errorf("order history called %d times, want 0", o.calls)
	}
}

func TestHandleWithoutFirstOrderCondition(t *testing.T) {
	basket := paymentCampaign(2, "basket", Conditions{
		Countries:      []string{"RU"},
		SKUIDs:         []int64{100, 101},
		PaymentMethods: []string{"card"},
	})
	s, d, o := newTestService(basket)

	if err := s.Handle(context.Background(), payment(42)); err != nil {
		t.Fatalf("Handle() error = %v", err)
	}
	if len(d.granted) != 1 {
		t.Errorf("granted %d discounts, want 1", len(d.granted))
	}
	if o.calls != 0 {
		t.Errorf("order history called %d times, want 0", o.calls)
	}
}

func TestHandleOrderHistoryError(t *testing.T) {
	firstOrder := paymentCampaign(1, "first_order", Conditions{FirstOrder: true})
	s, d, o := newTestService(firstOrder)
	o.err = errors.New("order-history: 503 unavailable")

	if err := s.Handle(context.Background(), payment(42)); err == nil {
		t.Fatal("Handle() error = nil, want error to retry the message")
	}
	if len(d.granted) != 0 {
		t.Errorf("granted %d discounts, want 0", len(d.granted))
	}
}
