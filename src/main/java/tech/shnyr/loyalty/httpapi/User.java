package tech.shnyr.loyalty.httpapi;

/**
 * The user context passed by the API gateway in headers.
 */
public record User(long id, String country, int cityId, String segment) {
}
