package tech.shnyr.loyalty.promo;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.times;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

import java.time.OffsetDateTime;
import java.util.List;
import java.util.Optional;
import java.util.stream.Stream;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;

import tech.shnyr.loyalty.campaigns.Campaign;
import tech.shnyr.loyalty.campaigns.CampaignEvent;
import tech.shnyr.loyalty.campaigns.CampaignService;
import tech.shnyr.loyalty.campaigns.Trigger;
import tech.shnyr.loyalty.discounts.Discount;
import tech.shnyr.loyalty.promo.PromoService.ApplyRequest;
import tech.shnyr.loyalty.promo.PromoService.PromoNotFoundException;

class PromoServiceTest {

    static Stream<Arguments> normalizeCodeCases() {
        return Stream.of(
                Arguments.of("as stored", "WELCOME300", "WELCOME300"),
                Arguments.of("lower case", "welcome300", "WELCOME300"),
                Arguments.of("mixed case", "WeLcOmE300", "WELCOME300"),
                Arguments.of("surrounding spaces", "  WELCOME300 \n", "WELCOME300"),
                Arguments.of("tab", "\tWELCOME300", "WELCOME300"),
                Arguments.of("zero-width space inside", "WELC\u200bOME300", "WELCOME300"),
                Arguments.of("zero-width joiner and non-joiner", "\u200dWELCOME\u200c300", "WELCOME300"),
                Arguments.of("word joiner", "WELCOME\u2060300", "WELCOME300"),
                Arguments.of("byte order mark", "\ufeffWELCOME300", "WELCOME300"),
                Arguments.of("soft hyphen", "WEL\u00adCOME300", "WELCOME300"),
                Arguments.of("no-break space inside", "WELCOME\u00a0300", "WELCOME300"),
                Arguments.of("narrow no-break space at the end", "WELCOME300\u202f", "WELCOME300"),
                Arguments.of("full-width letters and digits", "ＷＥＬＣＯＭＥ３００", "WELCOME300"),
                Arguments.of("full-width digits only", "AUTUMN１５", "AUTUMN15"),
                Arguments.of("full-width lower case", "ａｕｔｕｍｎ15", "AUTUMN15"),
                Arguments.of("code with a dash", "pulse-93kd", "PULSE-93KD"),
                Arguments.of("pasted from a message", " \u200bautumn１５\u00a0", "AUTUMN15"),
                Arguments.of("empty", "", ""),
                Arguments.of("only invisible characters", "\u200b\u00ad\ufeff", ""));
    }

    @ParameterizedTest(name = "{0}")
    @MethodSource("normalizeCodeCases")
    void normalizeCode(String name, String in, String want) {
        assertThat(PromoService.normalizeCode(in)).isEqualTo(want);
    }

    @Test
    void applyNormalizedInput() {
        CampaignService campaigns = mock(CampaignService.class);
        Campaign campaign = promoCampaign("WELCOME300");
        when(campaigns.byPromoCode(anyString())).thenReturn(Optional.empty());
        when(campaigns.byPromoCode("WELCOME300")).thenReturn(Optional.of(campaign));
        when(campaigns.applyPromoCode(any(Campaign.class), any(CampaignEvent.class))).thenReturn(discount());
        PromoService promo = new PromoService(campaigns);

        List<String> inputs = List.of(
                "WELCOME300", "welcome300", "WELC\u200bOME300", "ＷＥＬＣＯＭＥ３００", "WELCOME\u00a0300 ");
        for (String in : inputs) {
            assertThat(promo.apply(request(in))).isNotNull();
        }
        verify(campaigns, times(inputs.size())).applyPromoCode(any(Campaign.class), any(CampaignEvent.class));

        assertThatThrownBy(() -> promo.apply(request("WELCOME30"))).isInstanceOf(PromoNotFoundException.class);
    }

    private static ApplyRequest request(String code) {
        return new ApplyRequest(7, "RU", 1, "", code, PromoService.CHANNEL_MANUAL);
    }

    private static Campaign promoCampaign(String code) {
        OffsetDateTime now = OffsetDateTime.now();
        return new Campaign(1, "welcome_300", Trigger.PROMO_CODE, code, null, null, null,
                now.minusDays(1), null, true, null, now.minusDays(1));
    }

    private static Discount discount() {
        OffsetDateTime now = OffsetDateTime.now();
        return new Discount(1, 7, 1L, PromoService.CHANNEL_MANUAL, null, null, 300, null,
                now.plusDays(14), null, null, null, null, now);
    }
}
