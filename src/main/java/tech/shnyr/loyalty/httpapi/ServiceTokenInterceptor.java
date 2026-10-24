package tech.shnyr.loyalty.httpapi;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.http.HttpStatus;
import org.springframework.web.servlet.HandlerInterceptor;

import tech.shnyr.loyalty.web.ApiException;

/**
 * Lets in only the orders and pricing services, by X-Service-Token.
 */
class ServiceTokenInterceptor implements HandlerInterceptor {

    private final byte[] token;

    ServiceTokenInterceptor(String token) {
        this.token = token.getBytes(StandardCharsets.UTF_8);
    }

    @Override
    public boolean preHandle(HttpServletRequest request, HttpServletResponse response, Object handler) {
        String got = request.getHeader("X-Service-Token");
        if (got == null || !MessageDigest.isEqual(got.getBytes(StandardCharsets.UTF_8), token)) {
            throw new ApiException(HttpStatus.UNAUTHORIZED, "unauthorized", null);
        }
        return true;
    }
}
