package tech.shnyr.loyalty.campaigns;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyLong;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.times;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.verifyNoInteractions;
import static org.mockito.Mockito.when;

import java.time.Clock;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.List;

import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.mockito.ArgumentCaptor;

import tech.shnyr.loyalty.clients.orderhistory.OrderHistoryClient;
import tech.shnyr.loyalty.clients.orderhistory.OrderHistoryException;
import tech.shnyr.loyalty.config.PromoProperties;
import tech.shnyr.loyalty.discounts.DiscountService;
import tech.shnyr.loyalty.discounts.GrantRequest;
import tech.shnyr.loyalty.referrals.ReferralService;

class CampaignServiceTest {

    private static final OffsetDateTime NOW = OffsetDateTime.of(2026, 10, 20, 12, 0, 0, 0, ZoneOffset.UTC);

    private CampaignRepository repo;
    private DiscountService discounts;
    private OrderHistoryClient orders;
    private CampaignService service;

    @BeforeEach
    void setUp() {
        repo = mock(CampaignRepository.class);
        discounts = mock(DiscountService.class);
        orders = mock(OrderHistoryClient.class);
        Clock clock = Clock.fixed(Instant.from(NOW), ZoneOffset.UTC);
        service = new CampaignService(repo, discounts, mock(ReferralService.class), orders,
                new PromoProperties(2), clock);
    }

    @ParameterizedTest(name = "{0}")
    @CsvSource({
            "first order, 1, 1",
            "second order, 2, 0",
            "regular customer, 37, 0",
            "no orders in history, 0, 0",
    })
    void handleFirstOrder(String name, int ordersInHistory, int wantGranted) {
        active(paymentCampaign(1, "first_order", conditions(List.of("RU"), List.of(), List.of(), true)));
        when(orders.countOrders(42)).thenReturn(ordersInHistory);

        service.handle(payment(42, "RU"));

        ArgumentCaptor<GrantRequest> granted = ArgumentCaptor.forClass(GrantRequest.class);
        verify(discounts, times(wantGranted)).grant(granted.capture());
        if (wantGranted == 1) {
            GrantRequest g = granted.getValue();
            assertThat(g.campaignId()).isEqualTo(1);
            assertThat(g.percent()).isEqualTo(20);
            assertThat(g.expiresAt()).isEqualTo(NOW.plusDays(14));
            assertThat(g.sendPush()).isTrue();
        }
    }

    @Test
    void handleFirstOrderOtherCountry() {
        active(paymentCampaign(1, "first_order", conditions(List.of("RU"), List.of(), List.of(), true)));
        when(orders.countOrders(42)).thenReturn(1);

        service.handle(payment(42, "KZ"));

        verify(discounts, never()).grant(any());
        verifyNoInteractions(orders);
    }

    @Test
    void handleWithoutFirstOrderCondition() {
        active(paymentCampaign(2, "basket", conditions(List.of("RU"), List.of(100L, 101L), List.of("card"), false)));

        service.handle(payment(42, "RU"));

        verify(discounts).grant(any());
        verifyNoInteractions(orders);
    }

    @Test
    void handleOrderHistoryError() {
        active(paymentCampaign(1, "first_order", conditions(List.of(), List.of(), List.of(), true)));
        when(orders.countOrders(anyLong())).thenThrow(new OrderHistoryException(503, "unavailable", ""));

        assertThatThrownBy(() -> service.handle(payment(42, "RU"))).isInstanceOf(OrderHistoryException.class);
        verify(discounts, never()).grant(any());
    }

    private void active(Campaign... campaigns) {
        when(repo.activeByTrigger(eq(Trigger.PAYMENT_SUCCEEDED), any())).thenReturn(List.of(campaigns));
    }

    private static Conditions conditions(List<String> countries, List<Long> skuIds, List<String> paymentMethods,
                                         boolean firstOrder) {
        return new Conditions(countries, null, null, 0, 0, skuIds, paymentMethods, false, firstOrder, null, null, 0);
    }

    private static Campaign paymentCampaign(long id, String slug, Conditions cond) {
        return new Campaign(id, slug, Trigger.PAYMENT_SUCCEEDED, null, cond, RewardType.DISCOUNT,
                new RewardParams(20, 0, 0, 14, null), NOW.minusDays(1), null, true, null, NOW.minusDays(1));
    }

    private static CampaignEvent payment(long userId, String country) {
        return CampaignEvent.paymentSucceeded(userId, country, 0, null, "pay-1", "card", List.of(100L));
    }
}
