// Package consumers holds handlers of the Kafka topics the service reads.
// Topics, groups, deadlines and retries are set in config/consumers.yaml.
package consumers

import (
	"context"
	"encoding/json"
	"log/slog"
	"time"

	"git.shnyr.tech/loyalty/loyalty/internal/campaigns"
	"git.shnyr.tech/loyalty/loyalty/internal/kafkaconsumer"
)

type CampaignHandler interface {
	Handle(ctx context.Context, ev campaigns.Event) error
}

// paymentSucceeded is the payload of payments.payment-succeeded
// (api/events/payment-succeeded.yaml).
type paymentSucceeded struct {
	EventID       string        `json:"event_id"`
	OccurredAt    time.Time     `json:"occurred_at"`
	PaymentID     string        `json:"payment_id"`
	OrderID       int64         `json:"order_id"`
	UserID        int64         `json:"user_id"`
	Country       string        `json:"country"`
	CityID        int           `json:"city_id"`
	UserSegment   string        `json:"user_segment"`
	Amount        int64         `json:"amount"`
	Currency      string        `json:"currency"`
	PaymentMethod string        `json:"payment_method"`
	Items         []paymentItem `json:"items"`
}

type paymentItem struct {
	SKUID    int64 `json:"sku_id"`
	Quantity int   `json:"quantity"`
	Price    int64 `json:"price"`
}

type PaymentSucceeded struct {
	campaigns CampaignHandler
	log       *slog.Logger
}

func NewPaymentSucceeded(c CampaignHandler, log *slog.Logger) *PaymentSucceeded {
	return &PaymentSucceeded{campaigns: c, log: log}
}

// Handle runs the payment_succeeded campaigns for a paid order.
func (h *PaymentSucceeded) Handle(ctx context.Context, msg kafkaconsumer.Message) error {
	var evt paymentSucceeded
	if err := json.Unmarshal(msg.Value, &evt); err != nil {
		// Redelivery will not fix a broken payload.
		h.log.ErrorContext(ctx, "malformed payment event",
			"partition", msg.Partition, "offset", msg.Offset, "err", err)
		return nil
	}
	if evt.UserID == 0 || evt.PaymentID == "" {
		h.log.ErrorContext(ctx, "incomplete payment event",
			"event_id", evt.EventID, "partition", msg.Partition, "offset", msg.Offset)
		return nil
	}

	skuIDs := make([]int64, 0, len(evt.Items))
	for _, it := range evt.Items {
		skuIDs = append(skuIDs, it.SKUID)
	}

	return h.campaigns.Handle(ctx, campaigns.Event{
		Trigger:       campaigns.TriggerPaymentSucceeded,
		UserID:        evt.UserID,
		Country:       evt.Country,
		CityID:        evt.CityID,
		Segment:       evt.UserSegment,
		PaymentID:     evt.PaymentID,
		PaymentMethod: evt.PaymentMethod,
		SKUIDs:        skuIDs,
	})
}
