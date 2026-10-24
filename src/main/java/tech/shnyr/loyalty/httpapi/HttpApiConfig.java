package tech.shnyr.loyalty.httpapi;

import java.util.List;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Configuration;
import org.springframework.web.method.support.HandlerMethodArgumentResolver;
import org.springframework.web.servlet.config.annotation.InterceptorRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;

@Configuration
class HttpApiConfig implements WebMvcConfigurer {

    private final String ordersToken;

    HttpApiConfig(@Value("${LOYALTY_ORDERS_TOKEN:}") String ordersToken) {
        this.ordersToken = ordersToken;
    }

    @Override
    public void addArgumentResolvers(List<HandlerMethodArgumentResolver> resolvers) {
        resolvers.add(new UserArgumentResolver());
    }

    @Override
    public void addInterceptors(InterceptorRegistry registry) {
        registry.addInterceptor(new ServiceTokenInterceptor(ordersToken)).addPathPatterns("/v1/orders/**");
    }
}
