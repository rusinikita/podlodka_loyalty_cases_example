// Package admin serves the admin panel endpoints (/admin/v1): campaigns,
// compensation discounts and user rewards.
package admin

import (
	"errors"
	"log/slog"
	"net/http"
	"strconv"

	"github.com/go-chi/chi/v5"

	"git.shnyr.tech/loyalty/loyalty/internal/campaigns"
	"git.shnyr.tech/loyalty/loyalty/internal/discounts"
	"git.shnyr.tech/loyalty/loyalty/internal/httpx"
	"git.shnyr.tech/loyalty/loyalty/internal/referrals"
)

const pageSize = 50

type Handler struct {
	campaigns        *campaigns.Service
	discounts        *discounts.Service
	referrals        *referrals.Service
	compensationRole string
	log              *slog.Logger
}

func NewHandler(c *campaigns.Service, d *discounts.Service, r *referrals.Service, compensationRole string, log *slog.Logger) *Handler {
	return &Handler{campaigns: c, discounts: d, referrals: r, compensationRole: compensationRole, log: log}
}

func (h *Handler) Routes() http.Handler {
	r := chi.NewRouter()
	r.Use(withOperator)

	r.Get("/campaigns", h.searchCampaigns)
	r.With(requireRole(RoleLoyaltyAdmin)).Post("/campaigns", h.createCampaign)
	r.With(requireRole(RoleSupportL2)).Get("/campaigns/{id}", h.getCampaign)
	r.With(requireRole(RoleLoyaltyAdmin)).Patch("/campaigns/{id}", h.updateCampaign)

	r.Get("/users/{user_id}/discounts", h.userDiscounts)
	r.With(requireRole(h.compensationRole)).Post("/users/{user_id}/discounts", h.grantCompensation)
	r.Get("/users/{user_id}/referral-rewards", h.userReferralRewards)
	return r
}

func (h *Handler) searchCampaigns(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	cursor, err := httpx.Cursor(r)
	if err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "invalid cursor")
		return
	}
	f := campaigns.SearchFilter{
		Query:   q.Get("query"),
		Trigger: campaigns.Trigger(q.Get("trigger")),
		AfterID: cursor,
		Limit:   pageSize,
	}
	if s := q.Get("active"); s != "" {
		active, err := strconv.ParseBool(s)
		if err != nil {
			httpx.Error(w, http.StatusBadRequest, "bad_request", "invalid active")
			return
		}
		f.Active = &active
	}

	items, err := h.campaigns.Search(r.Context(), f)
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

func (h *Handler) createCampaign(w http.ResponseWriter, r *http.Request) {
	var in campaigns.Input
	if err := httpx.Decode(r, &in); err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}
	c, err := h.campaigns.Create(r.Context(), in, operatorFrom(r).Login)
	if err != nil {
		h.campaignError(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, c)
}

func (h *Handler) getCampaign(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "id")
	if !ok {
		return
	}
	c, err := h.campaigns.Get(r.Context(), id)
	if err != nil {
		h.campaignError(w, r, err)
		return
	}

	var activations int64
	switch c.RewardType {
	case campaigns.RewardDiscount:
		activations, err = h.discounts.CountByCampaign(r.Context(), c.ID)
	case campaigns.RewardReferral:
		activations, err = h.referrals.CountByCampaign(r.Context(), c.ID)
	}
	if err != nil {
		httpx.Internal(w, r, h.log, err)
		return
	}

	httpx.JSON(w, http.StatusOK, struct {
		campaigns.Campaign
		Activations int64 `json:"activations"`
	}{c, activations})
}

func (h *Handler) updateCampaign(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "id")
	if !ok {
		return
	}
	var in campaigns.Input
	if err := httpx.Decode(r, &in); err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}
	c, err := h.campaigns.Update(r.Context(), id, in, operatorFrom(r).Login)
	if err != nil {
		h.campaignError(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, c)
}

func (h *Handler) campaignError(w http.ResponseWriter, r *http.Request, err error) {
	switch {
	case errors.Is(err, campaigns.ErrNotFound):
		httpx.Error(w, http.StatusNotFound, "not_found", "")
	case errors.Is(err, campaigns.ErrConflict):
		httpx.Error(w, http.StatusConflict, "conflict", err.Error())
	case errors.Is(err, campaigns.ErrInvalid):
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
	default:
		httpx.Internal(w, r, h.log, err)
	}
}

func (h *Handler) userDiscounts(w http.ResponseWriter, r *http.Request) {
	userID, ok := pathID(w, r, "user_id")
	if !ok {
		return
	}
	cursor, err := httpx.Cursor(r)
	if err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "invalid cursor")
		return
	}
	items, err := h.discounts.ByUser(r.Context(), userID, cursor, pageSize)
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

func (h *Handler) grantCompensation(w http.ResponseWriter, r *http.Request) {
	userID, ok := pathID(w, r, "user_id")
	if !ok {
		return
	}
	var body struct {
		Percent   int    `json:"percent"`
		MaxAmount *int   `json:"max_amount"`
		TTLDays   int    `json:"ttl_days"`
		Comment   string `json:"comment"`
	}
	if err := httpx.Decode(r, &body); err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
		return
	}

	d, err := h.discounts.GrantCompensation(r.Context(), discounts.CompensationRequest{
		UserID:    userID,
		Percent:   body.Percent,
		MaxAmount: body.MaxAmount,
		TTLDays:   body.TTLDays,
		Operator:  operatorFrom(r).Login,
		Comment:   body.Comment,
	})
	switch {
	case err == nil:
		httpx.JSON(w, http.StatusCreated, d)
	case errors.Is(err, discounts.ErrCompensationLimit):
		httpx.Error(w, http.StatusUnprocessableEntity, "compensation_limit", "")
	case errors.Is(err, discounts.ErrCommentRequired):
		httpx.Error(w, http.StatusBadRequest, "bad_request", err.Error())
	default:
		httpx.Internal(w, r, h.log, err)
	}
}

func (h *Handler) userReferralRewards(w http.ResponseWriter, r *http.Request) {
	userID, ok := pathID(w, r, "user_id")
	if !ok {
		return
	}
	items, err := h.referrals.ByUser(r.Context(), userID, 0, pageSize)
	if err != nil {
		httpx.Internal(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"items": httpx.OrEmpty(items)})
}

func pathID(w http.ResponseWriter, r *http.Request, name string) (int64, bool) {
	id, err := strconv.ParseInt(chi.URLParam(r, name), 10, 64)
	if err != nil || id <= 0 {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "invalid "+name)
		return 0, false
	}
	return id, true
}
