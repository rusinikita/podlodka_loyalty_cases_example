package tech.shnyr.loyalty.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

/**
 * @param maxPerMonth promo codes a user can use in a calendar month
 */
@ConfigurationProperties("promo")
public record PromoProperties(int maxPerMonth) {
}
