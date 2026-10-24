// Package httpx has JSON helpers shared by the HTTP handlers.
package httpx

import (
	"encoding/json"
	"log/slog"
	"net/http"
	"strconv"
)

type errorBody struct {
	Error   string `json:"error"`
	Message string `json:"message,omitempty"`
}

func JSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// Error writes an API error: code is the machine-readable error from the
// spec, message is optional.
func Error(w http.ResponseWriter, status int, code, message string) {
	JSON(w, status, errorBody{Error: code, Message: message})
}

// Internal logs err and writes 500.
func Internal(w http.ResponseWriter, r *http.Request, log *slog.Logger, err error) {
	log.ErrorContext(r.Context(), "request failed", "method", r.Method, "path", r.URL.Path, "err", err)
	Error(w, http.StatusInternalServerError, "internal", "")
}

func Decode(r *http.Request, v any) error {
	dec := json.NewDecoder(r.Body)
	dec.DisallowUnknownFields()
	return dec.Decode(v)
}

// Cursor parses the cursor query parameter: the id of the last item of the
// previous page, 0 for the first page.
func Cursor(r *http.Request) (int64, error) {
	s := r.URL.Query().Get("cursor")
	if s == "" {
		return 0, nil
	}
	return strconv.ParseInt(s, 10, 64)
}

// NextCursor returns the cursor of the next page, or nil if the page is
// not full.
func NextCursor(lastID int64, got, limit int) *string {
	if got < limit {
		return nil
	}
	s := strconv.FormatInt(lastID, 10)
	return &s
}

// OrEmpty makes a nil slice encode as [] instead of null.
func OrEmpty[T any](items []T) []T {
	if items == nil {
		return []T{}
	}
	return items
}
