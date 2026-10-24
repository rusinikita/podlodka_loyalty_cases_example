package tech.shnyr.loyalty.httpapi;

import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import tech.shnyr.loyalty.discounts.Discount;
import tech.shnyr.loyalty.discounts.DiscountService;
import tech.shnyr.loyalty.promo.PromoService;
import tech.shnyr.loyalty.promo.PromoService.ApplyRequest;
import tech.shnyr.loyalty.promo.PromoService.PromoException;
import tech.shnyr.loyalty.referrals.ReferralService;
import tech.shnyr.loyalty.referrals.Reward;
import tech.shnyr.loyalty.web.ApiError;
import tech.shnyr.loyalty.web.ApiException;
import tech.shnyr.loyalty.web.Items;
import tech.shnyr.loyalty.web.Page;

/**
 * Endpoints of the app (/v1), see api/loyalty-api.yaml.
 */
@RestController
@RequestMapping("/v1")
public class AppController {

    private static final int PAGE_SIZE = 50;

    private final PromoService promo;
    private final DiscountService discounts;
    private final ReferralService referrals;

    public AppController(PromoService promo, DiscountService discounts, ReferralService referrals) {
        this.promo = promo;
        this.discounts = discounts;
        this.referrals = referrals;
    }

    record ApplyPromoBody(String code, String channel) {
    }

    @PostMapping("/promo/apply")
    Discount applyPromo(User user, @RequestBody ApplyPromoBody body) {
        if (!PromoService.CHANNEL_DEEPLINK.equals(body.channel()) && !PromoService.CHANNEL_MANUAL.equals(body.channel())) {
            throw ApiException.badRequest("unknown channel");
        }
        return promo.apply(new ApplyRequest(
                user.id(),
                user.country(),
                user.cityId(),
                user.segment(),
                body.code() == null ? "" : body.code(),
                body.channel()));
    }

    @GetMapping("/discounts/active")
    Items<Discount> activeDiscounts(User user) {
        return new Items<>(discounts.active(user.id()));
    }

    @GetMapping("/referrals/rewards")
    Page<Reward> referralRewards(User user, @RequestParam(defaultValue = "0") long cursor) {
        return Page.of(referrals.byUser(user.id(), cursor, PAGE_SIZE), PAGE_SIZE, Reward::id);
    }

    @ExceptionHandler(PromoException.class)
    ResponseEntity<ApiError> promoError(PromoException e) {
        return switch (e) {
            case PromoService.PromoNotFoundException x -> error(HttpStatus.NOT_FOUND, "promo_not_found");
            case PromoService.PromoAlreadyUsedException x -> error(HttpStatus.CONFLICT, "promo_already_used");
            case PromoService.PromoExpiredException x -> error(HttpStatus.UNPROCESSABLE_ENTITY, "promo_expired");
            case PromoService.PromoLimitReachedException x ->
                    error(HttpStatus.UNPROCESSABLE_ENTITY, "promo_activations_exhausted");
            case PromoService.PromoMonthlyLimitException x -> error(HttpStatus.UNPROCESSABLE_ENTITY, "promo_monthly_limit");
            case PromoService.PromoCountryUnavailableException x ->
                    error(HttpStatus.UNPROCESSABLE_ENTITY, "promo_country_unavailable");
        };
    }

    private static ResponseEntity<ApiError> error(HttpStatus status, String code) {
        return ResponseEntity.status(status).body(ApiError.of(code));
    }
}
