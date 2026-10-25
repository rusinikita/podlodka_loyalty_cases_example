package tech.shnyr.loyalty.referrals;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyInt;
import static org.mockito.ArgumentMatchers.anyLong;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.doAnswer;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

import java.time.Clock;
import java.time.Duration;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;

import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;

import tech.shnyr.loyalty.clients.antifraud.AntifraudClient;
import tech.shnyr.loyalty.clients.antifraud.RewardCheck;
import tech.shnyr.loyalty.clients.antifraud.RewardVerdict;
import tech.shnyr.loyalty.clients.antifraud.Verdict;
import tech.shnyr.loyalty.clients.payoutgateway.PayoutGatewayClient;

class ReferralServiceAntifraudTest {

    private static final OffsetDateTime NOW = OffsetDateTime.of(2026, 10, 20, 12, 0, 0, 0, ZoneOffset.UTC);

    private final TreeMap<Long, Reward> rewards = new TreeMap<>();
    private final Map<Long, Verdict> verdicts = new HashMap<>();
    private final List<List<Long>> calls = new ArrayList<>();

    private RewardRepository repo;
    private ReferralService service;

    @BeforeEach
    void setUp() {
        repo = mock(RewardRepository.class);
        when(repo.byStatusAfter(any(), anyLong(), anyInt())).thenAnswer(inv -> {
            RewardStatus status = inv.getArgument(0);
            long afterId = inv.getArgument(1);
            int limit = inv.getArgument(2);
            return rewards.tailMap(afterId, false).values().stream()
                    .filter(r -> r.status() == status.code())
                    .limit(limit)
                    .toList();
        });
        doAnswer(inv -> {
            long id = inv.getArgument(0);
            RewardStatus from = inv.getArgument(1);
            RewardStatus to = inv.getArgument(2);
            Reward r = rewards.get(id);
            if (r != null && r.status() == from.code()) {
                rewards.put(id, withStatus(r, to));
            }
            return null;
        }).when(repo).setStatus(anyLong(), any(), any());

        AntifraudClient antifraud = mock(AntifraudClient.class);
        when(antifraud.verdicts(any())).thenAnswer(inv -> {
            List<RewardCheck> items = inv.getArgument(0);
            calls.add(items.stream().map(RewardCheck::rewardId).toList());
            return items.stream()
                    .map(it -> new RewardVerdict(it.rewardId(), verdicts.getOrDefault(it.rewardId(), Verdict.PENDING),
                            null, null))
                    .toList();
        });

        service = new ReferralService(repo, mock(PayoutGatewayClient.class), antifraud,
                Clock.fixed(NOW.toInstant(), ZoneOffset.UTC));
    }

    static List<Arguments> verdictCases() {
        return List.of(
                Arguments.of("approved", waiting(1, Duration.ofHours(1)), Verdict.APPROVED, RewardStatus.PENDING),
                Arguments.of("rejected", waiting(2, Duration.ofHours(1)), Verdict.REJECTED, RewardStatus.REJECTED),
                Arguments.of("pending, fresh", waiting(3, Duration.ofHours(6)), Verdict.PENDING,
                        RewardStatus.WAITING_ANTIFRAUD_RESULT),
                Arguments.of("pending, almost a day", waiting(4, Duration.ofHours(23).plusMinutes(59)), Verdict.PENDING,
                        RewardStatus.WAITING_ANTIFRAUD_RESULT),
                Arguments.of("pending, a day", waiting(5, Duration.ofHours(24)), Verdict.PENDING, RewardStatus.PENDING),
                Arguments.of("pending, two days", waiting(6, Duration.ofHours(48)), Verdict.PENDING,
                        RewardStatus.PENDING),
                Arguments.of("rejected after a day", waiting(7, Duration.ofHours(30)), Verdict.REJECTED,
                        RewardStatus.REJECTED));
    }

    @ParameterizedTest(name = "{0}")
    @MethodSource("verdictCases")
    void checkAntifraud(String name, Reward reward, Verdict verdict, RewardStatus want) {
        rewards.put(reward.id(), reward);
        verdicts.put(reward.id(), verdict);

        service.checkAntifraud(500);

        assertThat(RewardStatus.nameOf(rewards.get(reward.id()).status()))
                .isEqualTo(RewardStatus.nameOf(want.code()));
    }

    @Test
    void checkAntifraudBatches() {
        for (long id = 1; id <= 7; id++) {
            rewards.put(id, waiting(id, Duration.ofHours(1)));
        }
        Reward paid = withStatus(waiting(8, Duration.ofHours(1)), RewardStatus.PAID);
        rewards.put(paid.id(), paid);

        service.checkAntifraud(3);

        assertThat(calls).containsExactly(List.of(1L, 2L, 3L), List.of(4L, 5L, 6L), List.of(7L));
        assertThat(rewards.get(8L).status()).isEqualTo(RewardStatus.PAID.code());
    }

    @Test
    void createWaitsForAntifraud() {
        when(repo.insert(any(), any())).thenReturn(true);

        service.create(new NewReward(17, 1, 2, 500));

        verify(repo).insert(any(), eq(RewardStatus.WAITING_ANTIFRAUD_RESULT));
    }

    private static Reward waiting(long id, Duration since) {
        OffsetDateTime at = NOW.minus(since);
        return new Reward(id, 17, 1000 + id, 2000 + id, 500, RewardStatus.WAITING_ANTIFRAUD_RESULT.code(), at, null, at);
    }

    private static Reward withStatus(Reward r, RewardStatus status) {
        return new Reward(r.id(), r.campaignId(), r.userId(), r.inviteeId(), r.amount(), status.code(), NOW,
                r.payoutId(), r.createdAt());
    }
}
