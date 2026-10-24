package tech.shnyr.loyalty.web;

import com.fasterxml.jackson.annotation.JsonInclude;

/**
 * Error body of the API: error is the machine-readable code from the spec,
 * message is optional.
 */
public record ApiError(String error, @JsonInclude(JsonInclude.Include.NON_EMPTY) String message) {

    public static ApiError of(String error) {
        return new ApiError(error, null);
    }
}
