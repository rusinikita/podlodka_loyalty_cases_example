package promo

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"testing"

	"git.shnyr.tech/loyalty/loyalty/internal/campaigns"
	"git.shnyr.tech/loyalty/loyalty/internal/discounts"
)

func TestNormalizeCode(t *testing.T) {
	tests := []struct {
		name string
		in   string
		want string
	}{
		{"as stored", "WELCOME300", "WELCOME300"},
		{"lower case", "welcome300", "WELCOME300"},
		{"mixed case", "WeLcOmE300", "WELCOME300"},
		{"surrounding spaces", "  WELCOME300 \n", "WELCOME300"},
		{"tab", "\tWELCOME300", "WELCOME300"},
		{"zero-width space inside", "WELC\u200bOME300", "WELCOME300"},
		{"zero-width joiner and non-joiner", "\u200dWELCOME\u200c300", "WELCOME300"},
		{"word joiner", "WELCOME\u2060300", "WELCOME300"},
		{"byte order mark", "\ufeffWELCOME300", "WELCOME300"},
		{"soft hyphen", "WEL\u00adCOME300", "WELCOME300"},
		{"no-break space inside", "WELCOME\u00a0300", "WELCOME300"},
		{"narrow no-break space at the end", "WELCOME300\u202f", "WELCOME300"},
		{"full-width letters and digits", "ＷＥＬＣＯＭＥ３００", "WELCOME300"},
		{"full-width digits only", "AUTUMN１５", "AUTUMN15"},
		{"full-width lower case", "ａｕｔｕｍｎ15", "AUTUMN15"},
		{"code with a dash", "pulse-93kd", "PULSE-93KD"},
		{"pasted from a message", " \u200bautumn１５\u00a0", "AUTUMN15"},
		{"empty", "", ""},
		{"only invisible characters", "\u200b\u00ad\ufeff", ""},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := normalizeCode(tt.in); got != tt.want {
				t.Errorf("normalizeCode(%q) = %q, want %q", tt.in, got, tt.want)
			}
		})
	}
}

type fakeCampaigns struct {
	byCode  map[string]campaigns.Campaign
	applied []string
}

func (f *fakeCampaigns) ByPromoCode(_ context.Context, code string) (campaigns.Campaign, error) {
	c, ok := f.byCode[code]
	if !ok {
		return campaigns.Campaign{}, campaigns.ErrNotFound
	}
	return c, nil
}

func (f *fakeCampaigns) ApplyPromoCode(_ context.Context, c campaigns.Campaign, _ campaigns.Event) (discounts.Discount, error) {
	f.applied = append(f.applied, *c.PromoCode)
	return discounts.Discount{ID: int64(len(f.applied))}, nil
}

func TestApplyNormalizedInput(t *testing.T) {
	code := "WELCOME300"
	c := &fakeCampaigns{byCode: map[string]campaigns.Campaign{
		code: {ID: 1, Slug: "welcome_300", Trigger: campaigns.TriggerPromoCode, PromoCode: &code},
	}}
	s := NewService(c, slog.New(slog.NewTextHandler(io.Discard, nil)))

	inputs := []string{"WELCOME300", "welcome300", "WELC\u200bOME300", "ＷＥＬＣＯＭＥ３００", "WELCOME\u00a0300 "}
	for _, in := range inputs {
		if _, err := s.Apply(context.Background(), ApplyRequest{UserID: 7, Code: in, Channel: ChannelManual}); err != nil {
			t.Errorf("Apply(%q) error = %v", in, err)
		}
	}
	if len(c.applied) != len(inputs) {
		t.Errorf("applied %d times, want %d", len(c.applied), len(inputs))
	}

	_, err := s.Apply(context.Background(), ApplyRequest{UserID: 7, Code: "WELCOME30", Channel: ChannelManual})
	if !errors.Is(err, ErrPromoNotFound) {
		t.Errorf("Apply(unknown code) error = %v, want %v", err, ErrPromoNotFound)
	}
}
