package tech.shnyr.loyalty.admin;

import com.fasterxml.jackson.annotation.JsonUnwrapped;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PatchMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestAttribute;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.ResponseStatus;
import org.springframework.web.bind.annotation.RestController;

import tech.shnyr.loyalty.campaigns.Campaign;
import tech.shnyr.loyalty.campaigns.CampaignAdminService;
import tech.shnyr.loyalty.campaigns.CampaignConflictException;
import tech.shnyr.loyalty.campaigns.CampaignInput;
import tech.shnyr.loyalty.campaigns.CampaignNotFoundException;
import tech.shnyr.loyalty.campaigns.InvalidCampaignException;
import tech.shnyr.loyalty.campaigns.SearchFilter;
import tech.shnyr.loyalty.campaigns.Trigger;
import tech.shnyr.loyalty.config.CompensationProperties;
import tech.shnyr.loyalty.discounts.CommentRequiredException;
import tech.shnyr.loyalty.discounts.CompensationLimitException;
import tech.shnyr.loyalty.discounts.CompensationRequest;
import tech.shnyr.loyalty.discounts.Discount;
import tech.shnyr.loyalty.discounts.DiscountService;
import tech.shnyr.loyalty.referrals.ReferralService;
import tech.shnyr.loyalty.referrals.Reward;
import tech.shnyr.loyalty.web.ApiError;
import tech.shnyr.loyalty.web.ApiException;
import tech.shnyr.loyalty.web.Items;
import tech.shnyr.loyalty.web.Page;

/**
 * Endpoints of the admin panel (/admin/v1): campaigns, compensation discounts
 * and user rewards.
 */
@RestController
@RequestMapping("/admin/v1")
public class AdminController {

    private static final int PAGE_SIZE = 50;

    private final CampaignAdminService campaigns;
    private final DiscountService discounts;
    private final ReferralService referrals;
    private final Role compensationRole;

    public AdminController(CampaignAdminService campaigns, DiscountService discounts, ReferralService referrals,
                           CompensationProperties compensation) {
        this.campaigns = campaigns;
        this.discounts = discounts;
        this.referrals = referrals;
        this.compensationRole = Role.of(compensation.role())
                .orElseThrow(() -> new IllegalStateException("unknown compensation role \"" + compensation.role() + "\""));
    }

    record CampaignView(@JsonUnwrapped Campaign campaign, long activations) {
    }

    record CompensationBody(int percent, Integer maxAmount, int ttlDays, String comment) {
    }

    @GetMapping("/campaigns")
    Page<Campaign> searchCampaigns(@RequestParam(defaultValue = "") String query,
                                   @RequestParam(defaultValue = "") String trigger,
                                   @RequestParam(required = false) Boolean active,
                                   @RequestParam(defaultValue = "0") long cursor) {
        Trigger t = null;
        if (!trigger.isEmpty()) {
            try {
                t = Trigger.of(trigger);
            } catch (IllegalArgumentException e) {
                throw ApiException.badRequest("invalid trigger");
            }
        }
        return Page.of(campaigns.search(new SearchFilter(query, t, active, cursor, PAGE_SIZE)), PAGE_SIZE, Campaign::id);
    }

    @PostMapping("/campaigns")
    @ResponseStatus(HttpStatus.CREATED)
    Campaign createCampaign(@RequestAttribute(Operator.ATTRIBUTE) Operator operator, @RequestBody CampaignInput in) {
        operator.require(Role.LOYALTY_ADMIN);
        return campaigns.create(in, operator.login());
    }

    @GetMapping("/campaigns/{id}")
    CampaignView getCampaign(@RequestAttribute(Operator.ATTRIBUTE) Operator operator, @PathVariable long id) {
        operator.require(Role.SUPPORT_L2);
        Campaign c = campaigns.get(positive(id, "id"));
        long activations = switch (c.rewardType()) {
            case DISCOUNT -> discounts.countByCampaign(c.id());
            case REFERRAL_REWARD -> referrals.countByCampaign(c.id());
        };
        return new CampaignView(c, activations);
    }

    @PatchMapping("/campaigns/{id}")
    Campaign updateCampaign(@RequestAttribute(Operator.ATTRIBUTE) Operator operator, @PathVariable long id,
                            @RequestBody CampaignInput in) {
        operator.require(Role.LOYALTY_ADMIN);
        return campaigns.update(positive(id, "id"), in, operator.login());
    }

    @GetMapping("/users/{user_id}/discounts")
    Page<Discount> userDiscounts(@PathVariable("user_id") long userId, @RequestParam(defaultValue = "0") long cursor) {
        return Page.of(discounts.byUser(positive(userId, "user_id"), cursor, PAGE_SIZE), PAGE_SIZE, Discount::id);
    }

    @PostMapping("/users/{user_id}/discounts")
    @ResponseStatus(HttpStatus.CREATED)
    Discount grantCompensation(@RequestAttribute(Operator.ATTRIBUTE) Operator operator,
                               @PathVariable("user_id") long userId, @RequestBody CompensationBody body) {
        operator.require(compensationRole);
        return discounts.grantCompensation(new CompensationRequest(
                positive(userId, "user_id"),
                body.percent(),
                body.maxAmount(),
                body.ttlDays(),
                operator.login(),
                body.comment()));
    }

    @GetMapping("/users/{user_id}/referral-rewards")
    Items<Reward> userReferralRewards(@PathVariable("user_id") long userId) {
        return new Items<>(referrals.byUser(positive(userId, "user_id"), 0, PAGE_SIZE));
    }

    @ExceptionHandler(CampaignNotFoundException.class)
    ResponseEntity<ApiError> notFound() {
        return ResponseEntity.status(HttpStatus.NOT_FOUND).body(ApiError.of("not_found"));
    }

    @ExceptionHandler(CampaignConflictException.class)
    ResponseEntity<ApiError> conflict(CampaignConflictException e) {
        return ResponseEntity.status(HttpStatus.CONFLICT).body(new ApiError("conflict", e.getMessage()));
    }

    @ExceptionHandler({InvalidCampaignException.class, CommentRequiredException.class})
    ResponseEntity<ApiError> invalid(RuntimeException e) {
        return ResponseEntity.badRequest().body(new ApiError("bad_request", e.getMessage()));
    }

    @ExceptionHandler(CompensationLimitException.class)
    ResponseEntity<ApiError> compensationLimit() {
        return ResponseEntity.unprocessableEntity().body(ApiError.of("compensation_limit"));
    }

    private static long positive(long id, String name) {
        if (id <= 0) {
            throw ApiException.badRequest("invalid " + name);
        }
        return id;
    }
}
