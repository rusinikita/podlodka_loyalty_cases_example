package tech.shnyr.loyalty.web;

import static net.logstash.logback.argument.StructuredArguments.kv;

import com.fasterxml.jackson.databind.exc.UnrecognizedPropertyException;
import jakarta.servlet.http.HttpServletRequest;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.http.converter.HttpMessageNotReadableException;
import org.springframework.web.ErrorResponse;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;
import org.springframework.web.method.annotation.MethodArgumentTypeMismatchException;

/**
 * Errors shared by all controllers. Domain errors are mapped by the
 * controllers themselves.
 */
@RestControllerAdvice
public class ApiExceptionHandler {

    private static final Logger log = LoggerFactory.getLogger(ApiExceptionHandler.class);

    @ExceptionHandler(ApiException.class)
    ResponseEntity<ApiError> api(ApiException e) {
        return ResponseEntity.status(e.status()).body(e.body());
    }

    @ExceptionHandler(HttpMessageNotReadableException.class)
    ResponseEntity<ApiError> unreadable(HttpMessageNotReadableException e) {
        String message = e.getCause() instanceof UnrecognizedPropertyException u
                ? "unknown field \"" + u.getPropertyName() + "\""
                : "invalid request body";
        return ResponseEntity.badRequest().body(new ApiError("bad_request", message));
    }

    @ExceptionHandler(MethodArgumentTypeMismatchException.class)
    ResponseEntity<ApiError> typeMismatch(MethodArgumentTypeMismatchException e) {
        return ResponseEntity.badRequest().body(new ApiError("bad_request", "invalid " + e.getName()));
    }

    @ExceptionHandler(Exception.class)
    ResponseEntity<ApiError> internal(Exception e, HttpServletRequest req) {
        if (e instanceof ErrorResponse er) {
            HttpStatus status = HttpStatus.valueOf(er.getStatusCode().value());
            String code = status == HttpStatus.NOT_FOUND ? "not_found" : "bad_request";
            return ResponseEntity.status(status).body(ApiError.of(code));
        }
        log.error("request failed", kv("method", req.getMethod()), kv("path", req.getRequestURI()), e);
        return ResponseEntity.internalServerError().body(ApiError.of("internal"));
    }
}
