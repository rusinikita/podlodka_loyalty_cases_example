package consumers

import (
	"context"
	"encoding/json"
	"log/slog"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/campaigns"
	"git.shnyr.tech/loyalty/loyalty/internal/kafkaconsumer"
)

// userRegistered is the payload of users.user-registered
// (api/events/user-registered.yaml).
type userRegistered struct {
	EventID    string    `json:"event_id"`
	OccurredAt time.Time `json:"occurred_at"`
	UserID     int64     `json:"user_id"`
	Country    string    `json:"country"`
	CityID     int       `json:"city_id"`
	InvitedBy  *int64    `json:"invited_by"`
}

type UserRegistered struct {
	campaigns CampaignHandler
	log       *slog.Logger
}

func NewUserRegistered(c CampaignHandler, log *slog.Logger) *UserRegistered {
	return &UserRegistered{campaigns: c, log: log}
}

// Handle runs the user_registered campaigns for a new user.
func (h *UserRegistered) Handle(ctx context.Context, msg kafkaconsumer.Message) error {
	var evt userRegistered
	if err := json.Unmarshal(msg.Value, &evt); err != nil {
		h.log.ErrorContext(ctx, "malformed registration event",
			"partition", msg.Partition, "offset", msg.Offset, "err", err)
		return nil
	}
	if evt.UserID == 0 {
		h.log.ErrorContext(ctx, "incomplete registration event",
			"event_id", evt.EventID, "partition", msg.Partition, "offset", msg.Offset)
		return nil
	}

	ev := campaigns.Event{
		Trigger: campaigns.TriggerUserRegistered,
		UserID:  evt.UserID,
		Country: evt.Country,
		CityID:  evt.CityID,
	}
	if evt.InvitedBy != nil {
		ev.InvitedBy = *evt.InvitedBy
	}
	return h.campaigns.Handle(ctx, ev)
}
