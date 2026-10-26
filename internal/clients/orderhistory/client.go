// Package orderhistory is the client of the order history service
// (api/order-history.yaml).
package orderhistory

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"time"
)

// APIError is a non-200 response of the order history service.
type APIError struct {
	StatusCode int
	Code       string
	Message    string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("order-history: %d %s: %s", e.StatusCode, e.Code, e.Message)
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

// CountOrders returns the number of the user's paid and completed orders for
// all time, of every channel and payment method. An unknown user has none.
func (c *Client) CountOrders(ctx context.Context, userID int64) (int, error) {
	url := c.baseURL + "/v1/users/" + strconv.FormatInt(userID, 10) + "/orders/count"
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("X-Service-Token", c.token)

	resp, err := c.http.Do(req)
	if err != nil {
		return 0, fmt.Errorf("order-history: %w", err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusNotFound:
		return 0, nil
	default:
		var apiErr struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		}
		_ = json.NewDecoder(resp.Body).Decode(&apiErr)
		return 0, &APIError{StatusCode: resp.StatusCode, Code: apiErr.Code, Message: apiErr.Message}
	}

	var out struct {
		Count int `json:"count"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return 0, fmt.Errorf("order-history: decode count: %w", err)
	}
	return out.Count, nil
}
