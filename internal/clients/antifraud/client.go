// Package antifraud is the client of the antifraud verdicts API
// (api/antifraud.yaml).
package antifraud

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"time"
)

// MaxBatch is the largest number of rewards accepted in one request.
const MaxBatch = 500

const source = "loyalty"

type Verdict string

const (
	VerdictApproved Verdict = "approved"
	VerdictRejected Verdict = "rejected"
	VerdictPending  Verdict = "pending"
)

type RewardCheck struct {
	RewardID  int64     `json:"reward_id"`
	InviterID int64     `json:"inviter_id"`
	InviteeID int64     `json:"invitee_id"`
	Amount    int       `json:"amount"`
	Currency  string    `json:"currency"`
	CreatedAt time.Time `json:"created_at"`
}

type RewardVerdict struct {
	RewardID  int64      `json:"reward_id"`
	Verdict   Verdict    `json:"verdict"`
	Reason    *string    `json:"reason"`
	CheckedAt *time.Time `json:"checked_at"`
}

// APIError is a non-200 response of the antifraud service.
type APIError struct {
	StatusCode int
	Code       string
	Message    string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("antifraud: %d %s: %s", e.StatusCode, e.Code, e.Message)
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

// Verdicts returns the verdicts for up to MaxBatch rewards, in the order of
// the request. A reward antifraud has not seen yet gets VerdictPending and
// a check is started for it.
func (c *Client) Verdicts(ctx context.Context, items []RewardCheck) ([]RewardVerdict, error) {
	if len(items) == 0 {
		return nil, nil
	}
	if len(items) > MaxBatch {
		return nil, fmt.Errorf("antifraud: batch of %d rewards, at most %d allowed", len(items), MaxBatch)
	}

	body, err := json.Marshal(struct {
		Source string        `json:"source"`
		Items  []RewardCheck `json:"items"`
	}{Source: source, Items: items})
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/v1/referral-rewards/verdicts", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Service-Token", c.token)

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("antifraud: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		var apiErr struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		}
		_ = json.NewDecoder(resp.Body).Decode(&apiErr)
		return nil, &APIError{StatusCode: resp.StatusCode, Code: apiErr.Code, Message: apiErr.Message}
	}

	var out struct {
		Items []RewardVerdict `json:"items"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, fmt.Errorf("antifraud: decode verdicts: %w", err)
	}
	if len(out.Items) != len(items) {
		return nil, fmt.Errorf("antifraud: got %d verdicts for %d rewards", len(out.Items), len(items))
	}
	return out.Items, nil
}
