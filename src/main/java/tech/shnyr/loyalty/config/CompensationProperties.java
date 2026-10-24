package tech.shnyr.loyalty.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

/**
 * Limits of a compensation discount granted by an operator in the admin panel.
 *
 * @param role the lowest admin role allowed to grant it
 */
@ConfigurationProperties("discounts.compensation")
public record CompensationProperties(int maxPercent, int ttlDays, String role) {
}
