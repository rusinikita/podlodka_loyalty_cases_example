// Package httpapi serves the app endpoints (/v1) and the endpoints for the
// orders and pricing services (/v1/orders), see api/loyalty-api.yaml.
package httpapi

import (
	"crypto/subtle"
	"errors"
	"log/slog"
	"net/http"
	"strconv"
	"time"

	"github.com/go-chi/chi/v5"

	"git.shnyr.tech/loyalty/loyalty/internal/discounts"
	"git.shnyr.tech/loyalty/loyalty/internal/httpx"
	"git.shnyr.tech/loyalty/loyalty/internal/promo"
	"git.shnyr.tech/loyalty/loyalty/internal/referrals"
)

const pageSize = 50

type Handler struct {
	promo        *promo.Service
	discounts    *discounts.Service
	referrals    *referrals.Service
	serviceToken string
	log          *slog.Logger
}

func NewHandler(p *promo.Service, d *discounts.Service, r *referrals.Service, serviceToken string, log *slog.Logger) *Handler {
	return &Handler{promo: p, discounts: d, referrals: r, serviceToken: serviceToken, log: log}
}

func (h *Handler) Routes() http.Handler {
	r := chi.NewRouter()
	r.Post("/promo/apply", h.applyPromo)
	r.Get("/discounts/active", h.activeDiscounts)
	r.Get("/referrals/rewards", h.referralRewards)

	r.Group(func(r chi.Router) {
		r.Use(h.requireServiceToken)
		r.Post("/orders/discount-quote", h.quoteDiscount)
		r.Post("/orders/{order_id}/redeem-discount", h.redeemDiscount)
	})
	return r
}

func (h *Handler) applyPromo(w http.ResponseWriter, r *http.Request) {
	u, err := userFromHeaders(r)
	if err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}
	var body struct {
		Code    string `json:"code"`
		Channel string `json:"channel"`
	}
	if err := httpx.Decode(r, &body); err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}
	if body.Channel != promo.ChannelDeeplink && body.Channel != promo.ChannelManual {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "unknown channel")
		return
	}

	d, err := h.promo.Apply(r.Context(), promo.ApplyRequest{
		UserID:  u.ID,
		Country: u.Country,
		CityID:  u.CityID,
		Segment: u.Segment,
		Code:    body.Code,
		Channel: body.Channel,
	})
	switch {
	case err == nil:
		httpx.JSON(w, http.StatusOK, d)
	case errors.Is(err, promo.ErrPromoNotFound):
		httpx.Error(w, http.StatusNotFound, "promo_not_found", "")
	case errors.Is(err, promo.ErrPromoAlreadyUsed):
		httpx.Error(w, http.StatusConflict, "promo_already_used", "")
	case errors.Is(err, promo.ErrPromoExpired):
		httpx.Error(w, http.StatusUnprocessableEntity, "promo_expired", "")
	case errors.Is(err, promo.ErrPromoLimitReached):
		httpx.Error(w, http.StatusUnprocessableEntity, "promo_activations_exhausted", "")
	case errors.Is(err, promo.ErrMonthlyLimit):
		httpx.Error(w, http.StatusUnprocessableEntity, "promo_monthly_limit", "")
	case errors.Is(err, promo.ErrPromoWrongCountry):
		httpx.Error(w, http.StatusUnprocessableEntity, "promo_country_unavailable", "")
	default:
		httpx.Internal(w, r, h.log, err)
	}
}

func (h *Handler) activeDiscounts(w http.ResponseWriter, r *http.Request) {
	u, err := userFromHeaders(r)
	if err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}
	items, err := h.discounts.Active(r.Context(), u.ID)
	if err != nil {
		httpx.Internal(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"items": httpx.OrEmpty(items)})
}

func (h *Handler) referralRewards(w http.ResponseWriter, r *http.Request) {
	u, err := userFromHeaders(r)
	if err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}
	cursor, err := httpx.Cursor(r)
	if err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "invalid cursor")
		return
	}
	items, err := h.referrals.ByUser(r.Context(), u.ID, cursor, pageSize)
	if err != nil {
		httpx.Internal(w, r, h.log, err)
		return
	}

	var next *string
	if len(items) > 0 {
		next = httpx.NextCursor(items[len(items)-1].ID, len(items), pageSize)
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"items": httpx.OrEmpty(items), "next_cursor": next})
}

func (h *Handler) quoteDiscount(w http.ResponseWriter, r *http.Request) {
	var body struct {
		UserID      int64     `json:"user_id"`
		OrderAmount int       `json:"order_amount"`
		CityID      int       `json:"city_id"`
		OrderedAt   time.Time `json:"ordered_at"`
	}
	if err := httpx.Decode(r, &body); err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}

	q, err := h.discounts.Quote(r.Context(), discounts.QuoteRequest{
		UserID:      body.UserID,
		OrderAmount: body.OrderAmount,
		CityID:      body.CityID,
		OrderedAt:   body.OrderedAt,
	})
	if err != nil {
		httpx.Internal(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"discount_id": q.DiscountID, "discount_amount": q.Amount})
}

func (h *Handler) redeemDiscount(w http.ResponseWriter, r *http.Request) {
	orderID, err := strconv.ParseInt(chi.URLParam(r, "order_id"), 10, 64)
	if err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "invalid order_id")
		return
	}
	var body struct {
		DiscountID int64 `json:"discount_id"`
		UserID     int64 `json:"user_id"`
	}
	if err := httpx.Decode(r, &body); err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}

	err = h.discounts.Redeem(r.Context(), body.DiscountID, body.UserID, orderID)
	switch {
	case err == nil:
		w.WriteHeader(http.StatusNoContent)
	case errors.Is(err, discounts.ErrUnavailable):
		httpx.Error(w, http.StatusConflict, "discount_unavailable", "")
	default:
		httpx.Internal(w, r, h.log, err)
	}
}

func (h *Handler) requireServiceToken(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got := r.Header.Get("X-Service-Token")
		if subtle.ConstantTimeCompare([]byte(got), []byte(h.serviceToken)) != 1 {
			httpx.Error(w, http.StatusUnauthorized, "unauthorized", "")
			return
		}
		next.ServeHTTP(w, r)
	})
}
