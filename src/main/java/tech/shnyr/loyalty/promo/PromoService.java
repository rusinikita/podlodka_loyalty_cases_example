package tech.shnyr.loyalty.promo;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.text.Normalizer;
import java.util.Locale;
import java.util.Optional;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

import tech.shnyr.loyalty.campaigns.Campaign;
import tech.shnyr.loyalty.campaigns.CampaignEvent;
import tech.shnyr.loyalty.campaigns.CampaignExpiredException;
import tech.shnyr.loyalty.campaigns.CampaignService;
import tech.shnyr.loyalty.campaigns.IneligibleException;
import tech.shnyr.loyalty.campaigns.MonthlyLimitException;
import tech.shnyr.loyalty.discounts.Discount;

/**
 * Applies promo codes entered by users: finds the campaign of the code and
 * passes it to campaigns.
 */
@Service
public class PromoService {

    private static final Logger log = LoggerFactory.getLogger(PromoService.class);

    public static final String CHANNEL_DEEPLINK = "deeplink";
    public static final String CHANNEL_MANUAL = "manual";

    private final CampaignService campaigns;

    public PromoService(CampaignService campaigns) {
        this.campaigns = campaigns;
    }

    public record ApplyRequest(long userId, String country, int cityId, String segment, String code, String channel) {
    }

    /**
     * Turns user input into the form promo codes are stored in. Codes are
     * often pasted from emails, messengers and notes, and the pasted text
     * carries invisible characters (zero-width spaces, soft hyphens,
     * non-breaking spaces) or full-width forms from some keyboards. NFKC
     * folds compatibility forms (full-width letters and digits, ligatures)
     * into plain ones. Invisible characters are dropped first, before NFKC
     * turns no-break spaces into ordinary ones.
     */
    static String normalizeCode(String raw) {
        StringBuilder sb = new StringBuilder(raw.length());
        raw.codePoints()
                .filter(cp -> !isInvisible(cp))
                .forEach(sb::appendCodePoint);
        String s = Normalizer.normalize(sb, Normalizer.Form.NFKC);
        return s.strip().toUpperCase(Locale.ROOT);
    }

    /**
     * Whether the code point is not rendered or is rendered as a blank inside
     * a code: format characters (ZWSP, ZWNJ, ZWJ, word joiner, BOM, soft
     * hyphen) and no-break spaces.
     */
    private static boolean isInvisible(int cp) {
        return switch (cp) {
            case '\u00A0', '\u2007', '\u202F' -> true;
            default -> Character.getType(cp) == Character.FORMAT;
        };
    }

    /**
     * Activates the promo code for the user: the user gets the discount of
     * the code's campaign for the next order.
     */
    public Discount apply(ApplyRequest req) {
        Optional<Campaign> found = campaigns.byPromoCode(normalizeCode(req.code()));
        if (found.isEmpty()) {
            log.info("promo not found", kv("input", req.code()), kv("user_id", req.userId()), kv("channel", req.channel()));
            throw new PromoNotFoundException();
        }
        Campaign c = found.get();

        Discount d;
        try {
            d = campaigns.applyPromoCode(c, CampaignEvent.promoCode(
                    req.userId(), req.country(), req.cityId(), req.segment(), req.channel()));
        } catch (IneligibleException e) {
            throw switch (e.kind()) {
                case NOT_ELIGIBLE -> hide(c, req, e.reason());
                case WRONG_COUNTRY -> new PromoCountryUnavailableException();
                case ALREADY_USED -> new PromoAlreadyUsedException();
                case ACTIVATIONS_EXHAUSTED -> new PromoLimitReachedException();
            };
        } catch (CampaignExpiredException e) {
            throw new PromoExpiredException();
        } catch (MonthlyLimitException e) {
            throw new PromoMonthlyLimitException();
        }

        log.info("promo applied",
                kv("campaign_id", c.id()), kv("user_id", req.userId()), kv("channel", req.channel()), kv("discount_id", d.id()));
        return d;
    }

    /**
     * Makes a promo code the user is not eligible for look non-existent:
     * targeted and not yet launched campaigns must not leak to everyone else.
     */
    private PromoNotFoundException hide(Campaign c, ApplyRequest req, String reason) {
        log.info("promo hidden",
                kv("campaign_id", c.id()), kv("user_id", req.userId()), kv("channel", req.channel()), kv("reason", reason));
        return new PromoNotFoundException();
    }

    public abstract static sealed class PromoException extends RuntimeException
            permits PromoNotFoundException, PromoExpiredException, PromoAlreadyUsedException,
            PromoLimitReachedException, PromoMonthlyLimitException, PromoCountryUnavailableException {

        PromoException(String message) {
            super(message);
        }
    }

    public static final class PromoNotFoundException extends PromoException {
        PromoNotFoundException() {
            super("promo not found");
        }
    }

    public static final class PromoExpiredException extends PromoException {
        PromoExpiredException() {
            super("promo expired");
        }
    }

    public static final class PromoAlreadyUsedException extends PromoException {
        PromoAlreadyUsedException() {
            super("promo already used");
        }
    }

    public static final class PromoLimitReachedException extends PromoException {
        PromoLimitReachedException() {
            super("promo activation limit reached");
        }
    }

    public static final class PromoMonthlyLimitException extends PromoException {
        PromoMonthlyLimitException() {
            super("monthly promo limit reached");
        }
    }

    public static final class PromoCountryUnavailableException extends PromoException {
        PromoCountryUnavailableException() {
            super("promo not available in the user's country");
        }
    }
}
