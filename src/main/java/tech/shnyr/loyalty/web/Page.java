package tech.shnyr.loyalty.web;

import java.util.List;
import java.util.function.ToLongFunction;

/**
 * A page of a list. nextCursor is the id of the last item, to be passed as the
 * cursor query parameter for the next page; null if the page is not full.
 */
public record Page<T>(List<T> items, String nextCursor) {

    public static <T> Page<T> of(List<T> items, int limit, ToLongFunction<T> id) {
        if (items.size() < limit) {
            return new Page<>(items, null);
        }
        return new Page<>(items, Long.toString(id.applyAsLong(items.getLast())));
    }
}
