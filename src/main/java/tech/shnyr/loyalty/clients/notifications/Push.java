package tech.shnyr.loyalty.clients.notifications;

import java.util.Map;

import com.fasterxml.jackson.annotation.JsonInclude;

public record Push(long userId, String template, @JsonInclude(JsonInclude.Include.NON_EMPTY) Map<String, String> params) {
}
