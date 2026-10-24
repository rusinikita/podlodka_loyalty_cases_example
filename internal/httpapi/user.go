package httpapi

import (
	"errors"
	"net/http"
	"strconv"
)

// user is the user context passed by the API gateway in headers.
type user struct {
	ID      int64
	Country string
	CityID  int
	Segment string
}

func userFromHeaders(r *http.Request) (user, error) {
	id, err := strconv.ParseInt(r.Header.Get("X-User-Id"), 10, 64)
	if err != nil || id <= 0 {
		return user{}, errors.New("invalid X-User-Id")
	}
	u := user{
		ID:      id,
		Country: r.Header.Get("X-User-Country"),
		Segment: r.Header.Get("X-User-Segment"),
	}
	if city := r.Header.Get("X-User-City-Id"); city != "" {
		if u.CityID, err = strconv.Atoi(city); err != nil {
			return user{}, errors.New("invalid X-User-City-Id")
		}
	}
	return u, nil
}
