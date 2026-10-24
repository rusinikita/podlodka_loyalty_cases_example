package tech.shnyr.loyalty.httpapi;

import org.springframework.core.MethodParameter;
import org.springframework.web.bind.support.WebDataBinderFactory;
import org.springframework.web.context.request.NativeWebRequest;
import org.springframework.web.method.support.HandlerMethodArgumentResolver;
import org.springframework.web.method.support.ModelAndViewContainer;

import tech.shnyr.loyalty.web.ApiException;

/**
 * Resolves a {@link User} controller argument from the X-User-* headers.
 */
class UserArgumentResolver implements HandlerMethodArgumentResolver {

    @Override
    public boolean supportsParameter(MethodParameter parameter) {
        return parameter.getParameterType() == User.class;
    }

    @Override
    public User resolveArgument(MethodParameter parameter, ModelAndViewContainer mav, NativeWebRequest request,
                                WebDataBinderFactory binderFactory) {
        long id;
        try {
            id = Long.parseLong(String.valueOf(request.getHeader("X-User-Id")));
        } catch (NumberFormatException e) {
            id = 0;
        }
        if (id <= 0) {
            throw ApiException.badRequest("invalid X-User-Id");
        }

        int cityId = 0;
        String city = request.getHeader("X-User-City-Id");
        if (city != null && !city.isEmpty()) {
            try {
                cityId = Integer.parseInt(city);
            } catch (NumberFormatException e) {
                throw ApiException.badRequest("invalid X-User-City-Id");
            }
        }
        return new User(id, request.getHeader("X-User-Country"), cityId, request.getHeader("X-User-Segment"));
    }
}
