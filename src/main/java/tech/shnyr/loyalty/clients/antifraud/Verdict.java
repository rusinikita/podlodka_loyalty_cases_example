package tech.shnyr.loyalty.clients.antifraud;

import com.fasterxml.jackson.annotation.JsonProperty;

public enum Verdict {
    @JsonProperty("approved") APPROVED,
    @JsonProperty("rejected") REJECTED,
    @JsonProperty("pending") PENDING
}
