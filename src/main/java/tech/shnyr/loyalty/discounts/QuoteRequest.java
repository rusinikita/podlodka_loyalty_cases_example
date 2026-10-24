package tech.shnyr.loyalty.discounts;

import java.time.OffsetDateTime;

public record QuoteRequest(long userId, int orderAmount, int cityId, OffsetDateTime orderedAt) {
}
