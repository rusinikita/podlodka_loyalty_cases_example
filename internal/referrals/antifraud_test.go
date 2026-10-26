package referrals

import (
	"context"
	"io"
	"log/slog"
	"slices"
	"testing"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/clients/antifraud"
)

var testNow = time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)

type fakeRepo struct {
	Repository
	rewards map[int64]*Reward
}

func (f *fakeRepo) ByStatusAfter(_ context.Context, status RewardStatus, afterID int64, limit int) ([]Reward, error) {
	var out []Reward
	for _, r := range f.rewards {
		if r.Status == status && r.ID > afterID {
			out = append(out, *r)
		}
	}
	slices.SortFunc(out, func(a, b Reward) int { return int(a.ID - b.ID) })
	if len(out) > limit {
		out = out[:limit]
	}
	return out, nil
}

func (f *fakeRepo) SetStatus(_ context.Context, id int64, from, to RewardStatus) error {
	if r := f.rewards[id]; r != nil && r.Status == from {
		r.Status = to
		r.StatusChangedAt = testNow
	}
	return nil
}

type fakeAntifraud struct {
	verdicts map[int64]antifraud.Verdict
	calls    [][]int64
}

func (f *fakeAntifraud) Verdicts(_ context.Context, items []antifraud.RewardCheck) ([]antifraud.RewardVerdict, error) {
	ids := make([]int64, len(items))
	out := make([]antifraud.RewardVerdict, len(items))
	for i, it := range items {
		ids[i] = it.RewardID
		v, ok := f.verdicts[it.RewardID]
		if !ok {
			v = antifraud.VerdictPending
		}
		out[i] = antifraud.RewardVerdict{RewardID: it.RewardID, Verdict: v}
	}
	f.calls = append(f.calls, ids)
	return out, nil
}

func newAntifraudTestService(rewards ...Reward) (*Service, *fakeRepo, *fakeAntifraud) {
	repo := &fakeRepo{rewards: map[int64]*Reward{}}
	for _, r := range rewards {
		repo.rewards[r.ID] = &r
	}
	af := &fakeAntifraud{verdicts: map[int64]antifraud.Verdict{}}
	s := NewService(repo, nil, af, slog.New(slog.NewTextHandler(io.Discard, nil)))
	s.now = func() time.Time { return testNow }
	return s, repo, af
}

func waiting(id int64, since time.Duration) Reward {
	return Reward{
		ID:              id,
		CampaignID:      17,
		UserID:          1000 + id,
		InviteeID:       2000 + id,
		Amount:          500,
		Status:          RewardWaitingAntifraudResult,
		StatusChangedAt: testNow.Add(-since),
		CreatedAt:       testNow.Add(-since),
	}
}

func TestCheckAntifraud(t *testing.T) {
	tests := []struct {
		name    string
		reward  Reward
		verdict antifraud.Verdict
		want    RewardStatus
	}{
		{"approved", waiting(1, time.Hour), antifraud.VerdictApproved, RewardPending},
		{"rejected", waiting(2, time.Hour), antifraud.VerdictRejected, RewardRejected},
		{"pending, fresh", waiting(3, 6*time.Hour), antifraud.VerdictPending, RewardWaitingAntifraudResult},
		{"pending, almost a day", waiting(4, 23*time.Hour+59*time.Minute), antifraud.VerdictPending, RewardWaitingAntifraudResult},
		{"pending, a day", waiting(5, 24*time.Hour), antifraud.VerdictPending, RewardPending},
		{"pending, two days", waiting(6, 48*time.Hour), antifraud.VerdictPending, RewardPending},
		{"rejected after a day", waiting(7, 30*time.Hour), antifraud.VerdictRejected, RewardRejected},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			s, repo, af := newAntifraudTestService(tt.reward)
			af.verdicts[tt.reward.ID] = tt.verdict

			if err := s.CheckAntifraud(context.Background(), 500); err != nil {
				t.Fatalf("CheckAntifraud() error = %v", err)
			}
			if got := repo.rewards[tt.reward.ID].Status; got != tt.want {
				t.Errorf("status = %s, want %s", got, tt.want)
			}
		})
	}
}

func TestCheckAntifraudBatches(t *testing.T) {
	var rewards []Reward
	for id := int64(1); id <= 7; id++ {
		rewards = append(rewards, waiting(id, time.Hour))
	}
	paid := Reward{ID: 8, Status: RewardPaid}
	s, repo, af := newAntifraudTestService(append(rewards, paid)...)

	if err := s.CheckAntifraud(context.Background(), 3); err != nil {
		t.Fatalf("CheckAntifraud() error = %v", err)
	}

	want := [][]int64{{1, 2, 3}, {4, 5, 6}, {7}}
	if !slices.EqualFunc(af.calls, want, slices.Equal) {
		t.Errorf("antifraud calls = %v, want %v", af.calls, want)
	}
	if got := repo.rewards[8].Status; got != RewardPaid {
		t.Errorf("paid reward status = %s, want paid", got)
	}
}

func TestCreateWaitsForAntifraud(t *testing.T) {
	repo := &insertRepo{}
	s := NewService(repo, nil, nil, slog.New(slog.NewTextHandler(io.Discard, nil)))

	err := s.Create(context.Background(), NewReward{CampaignID: 17, UserID: 1, InviteeID: 2, Amount: 500})
	if err != nil {
		t.Fatalf("Create() error = %v", err)
	}
	if len(repo.inserted) != 1 || repo.inserted[0].Status != RewardWaitingAntifraudResult {
		t.Errorf("inserted = %+v", repo.inserted)
	}
}

type insertRepo struct {
	Repository
	inserted []Reward
}

func (f *insertRepo) Insert(_ context.Context, r Reward) (bool, error) {
	f.inserted = append(f.inserted, r)
	return true, nil
}
