// Package payoutgateway is the client of payout-gateway (api/payout-gateway.yaml).
package payoutgateway

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"time"
)

var (
	ErrNoPayoutMethod = errors.New("no payout method")
	ErrUserBlocked    = errors.New("user blocked")
)

type PayoutRequest struct {
	IdempotencyKey string `json:"idempotency_key"`
	UserID         int64  `json:"user_id"`
	Amount         int    `json:"amount"`
	Currency       string `json:"currency"`
	Purpose        string `json:"purpose"`
}

type Payout struct {
	PayoutID  string    `json:"payout_id"`
	Status    string    `json:"status"`
	CardMask  string    `json:"card_mask"`
	CreatedAt time.Time `json:"created_at"`
}

// APIError is a response the client does not map to a sentinel error.
type APIError struct {
	StatusCode int
	Code       string
	Message    string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("payout-gateway: %d %s: %s", e.StatusCode, e.Code, e.Message)
}

type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

func New(baseURL, token string, timeout time.Duration) *Client {
	return &Client{
		baseURL: baseURL,
		token:   token,
		http:    &http.Client{Timeout: timeout},
	}
}

// Pay creates a payout. A repeated call with the same idempotency key returns
// the original payout.
func (c *Client) Pay(ctx context.Context, req PayoutRequest) (Payout, error) {
	body, err := json.Marshal(req)
	if err != nil {
		return Payout{}, err
	}
	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/v1/payouts", bytes.NewReader(body))
	if err != nil {
		return Payout{}, err
	}
	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("X-Service-Token", c.token)

	resp, err := c.http.Do(httpReq)
	if err != nil {
		return Payout{}, fmt.Errorf("payout-gateway: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusOK {
		var p Payout
		if err := json.NewDecoder(resp.Body).Decode(&p); err != nil {
			return Payout{}, fmt.Errorf("payout-gateway: decode payout: %w", err)
		}
		return p, nil
	}

	var apiErr struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	}
	_ = json.NewDecoder(resp.Body).Decode(&apiErr)
	switch apiErr.Code {
	case "no_payout_method":
		return Payout{}, ErrNoPayoutMethod
	case "user_blocked":
		return Payout{}, ErrUserBlocked
	}
	return Payout{}, &APIError{StatusCode: resp.StatusCode, Code: apiErr.Code, Message: apiErr.Message}
}
