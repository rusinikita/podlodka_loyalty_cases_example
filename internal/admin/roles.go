package admin

import (
	"context"
	"net/http"

	"git.shnyr.tech/loyalty/loyalty/internal/httpx"
)

// Roles of the admin panel, from the lowest. Each role can do everything the
// previous ones can (docs/admin_permissions.md).
const (
	RoleSupportL1       = "support_l1"
	RoleSupportL2       = "support_l2"
	RoleLoyaltyOperator = "loyalty_operator"
	RoleLoyaltyAdmin    = "loyalty_admin"
)

var roleRank = map[string]int{
	RoleSupportL1:       1,
	RoleSupportL2:       2,
	RoleLoyaltyOperator: 3,
	RoleLoyaltyAdmin:    4,
}

type operator struct {
	Login string
	Role  string
}

type operatorKey struct{}

// withOperator reads the operator passed by the admin panel in headers.
func withOperator(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		op := operator{
			Login: r.Header.Get("X-Operator-Login"),
			Role:  r.Header.Get("X-Operator-Role"),
		}
		if op.Login == "" || roleRank[op.Role] == 0 {
			httpx.Error(w, http.StatusForbidden, "forbidden", "")
			return
		}
		next.ServeHTTP(w, r.WithContext(context.WithValue(r.Context(), operatorKey{}, op)))
	})
}

func requireRole(min string) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if roleRank[operatorFrom(r).Role] < roleRank[min] {
				httpx.Error(w, http.StatusForbidden, "forbidden", "")
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}

func operatorFrom(r *http.Request) operator {
	op, _ := r.Context().Value(operatorKey{}).(operator)
	return op
}
