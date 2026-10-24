package tech.shnyr.loyalty.admin;

import java.util.Optional;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.web.servlet.HandlerInterceptor;

import tech.shnyr.loyalty.web.ApiException;

/**
 * Reads the operator passed by the admin panel in headers.
 */
class OperatorInterceptor implements HandlerInterceptor {

    @Override
    public boolean preHandle(HttpServletRequest request, HttpServletResponse response, Object handler) {
        String login = request.getHeader("X-Operator-Login");
        Optional<Role> role = Role.of(request.getHeader("X-Operator-Role"));
        if (login == null || login.isEmpty() || role.isEmpty()) {
            throw ApiException.forbidden();
        }
        request.setAttribute(Operator.ATTRIBUTE, new Operator(login, role.get()));
        return true;
    }
}
