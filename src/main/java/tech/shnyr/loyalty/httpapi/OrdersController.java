package tech.shnyr.loyalty.httpapi;

import java.time.OffsetDateTime;

import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.ResponseStatus;
import org.springframework.web.bind.annotation.RestController;

import tech.shnyr.loyalty.discounts.DiscountService;
import tech.shnyr.loyalty.discounts.DiscountUnavailableException;
import tech.shnyr.loyalty.discounts.Quote;
import tech.shnyr.loyalty.discounts.QuoteRequest;
import tech.shnyr.loyalty.web.ApiError;
import tech.shnyr.loyalty.web.ApiException;

/**
 * Endpoints for the orders and pricing services (/v1/orders), see
 * api/loyalty-api.yaml.
 */
@RestController
@RequestMapping("/v1/orders")
public class OrdersController {

    private final DiscountService discounts;

    public OrdersController(DiscountService discounts) {
        this.discounts = discounts;
    }

    record QuoteBody(long userId, int orderAmount, int cityId, OffsetDateTime orderedAt) {
    }

    record QuoteResponse(Long discountId, int discountAmount) {
    }

    record RedeemBody(long discountId, long userId) {
    }

    @PostMapping("/discount-quote")
    QuoteResponse quoteDiscount(@RequestBody QuoteBody body) {
        if (body.orderedAt() == null) {
            throw ApiException.badRequest("ordered_at is required");
        }
        Quote q = discounts.quote(new QuoteRequest(body.userId(), body.orderAmount(), body.cityId(), body.orderedAt()));
        return new QuoteResponse(q.discountId(), q.amount());
    }

    @PostMapping("/{order_id}/redeem-discount")
    @ResponseStatus(HttpStatus.NO_CONTENT)
    void redeemDiscount(@PathVariable("order_id") long orderId, @RequestBody RedeemBody body) {
        discounts.redeem(body.discountId(), body.userId(), orderId);
    }

    @ExceptionHandler(DiscountUnavailableException.class)
    ResponseEntity<ApiError> unavailable() {
        return ResponseEntity.status(HttpStatus.CONFLICT).body(ApiError.of("discount_unavailable"));
    }
}
