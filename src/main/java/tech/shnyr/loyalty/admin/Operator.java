package tech.shnyr.loyalty.admin;

import tech.shnyr.loyalty.web.ApiException;

/**
 * The admin panel user who made the request.
 */
record Operator(String login, Role role) {

    static final String ATTRIBUTE = "operator";

    void require(Role min) {
        if (!role.atLeast(min)) {
            throw ApiException.forbidden();
        }
    }
}
