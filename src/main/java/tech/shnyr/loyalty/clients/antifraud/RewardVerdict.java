package tech.shnyr.loyalty.clients.antifraud;

import java.time.OffsetDateTime;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;

@JsonIgnoreProperties(ignoreUnknown = true)
public record RewardVerdict(long rewardId, Verdict verdict, String reason, OffsetDateTime checkedAt) {
}
