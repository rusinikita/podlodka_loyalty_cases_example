package tech.shnyr.loyalty.consumers;

import java.io.IOException;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.SmartLifecycle;
import org.springframework.stereotype.Component;

import tech.shnyr.loyalty.config.ConsumersConfig;
import tech.shnyr.loyalty.kafka.MessageHandler;
import tech.shnyr.loyalty.kafka.PlatformConsumer;

/**
 * Starts a consumer for every entry of config/consumers.yaml. Topics, groups,
 * deadlines and retries are set there; handlers are bound by name.
 */
@Component
public class ConsumersRunner implements SmartLifecycle {

    private final List<PlatformConsumer> consumers = new ArrayList<>();
    private volatile boolean running;

    public ConsumersRunner(@Value("${loyalty.consumers-file}") Path file,
                           PaymentSucceededHandler paymentSucceeded,
                           UserRegisteredHandler userRegistered) throws IOException {
        Map<String, MessageHandler> handlers = Map.of(
                "payment_succeeded", paymentSucceeded,
                "user_registered", userRegistered);

        ConsumersConfig cfg = ConsumersConfig.load(file);
        for (ConsumersConfig.Consumer c : cfg.consumers()) {
            MessageHandler h = handlers.get(c.handler());
            if (h == null) {
                throw new IllegalStateException("consumer %s: unknown handler \"%s\"".formatted(c.name(), c.handler()));
            }
            consumers.add(new PlatformConsumer(cfg.kafka().brokers(), cfg.effective(c), h));
        }
    }

    @Override
    public void start() {
        consumers.forEach(PlatformConsumer::start);
        running = true;
    }

    @Override
    public void stop() {
        consumers.forEach(PlatformConsumer::stop);
        running = false;
    }

    @Override
    public boolean isRunning() {
        return running;
    }
}
