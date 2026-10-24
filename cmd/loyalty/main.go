// Command loyalty runs the loyalty service: HTTP API, Kafka consumers and
// workers in one process.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"
	"github.com/segmentio/kafka-go"
	"golang.org/x/sync/errgroup"

	"git.shnyr.tech/loyalty/loyalty/internal/admin"
	"git.shnyr.tech/loyalty/loyalty/internal/campaigns"
	"git.shnyr.tech/loyalty/loyalty/internal/clients/notifications"
	"git.shnyr.tech/loyalty/loyalty/internal/clients/payoutgateway"
	"git.shnyr.tech/loyalty/loyalty/internal/config"
	"git.shnyr.tech/loyalty/loyalty/internal/consumers"
	"git.shnyr.tech/loyalty/loyalty/internal/discounts"
	"git.shnyr.tech/loyalty/loyalty/internal/httpapi"
	"git.shnyr.tech/loyalty/loyalty/internal/kafkaconsumer"
	"git.shnyr.tech/loyalty/loyalty/internal/postgres"
	"git.shnyr.tech/loyalty/loyalty/internal/promo"
	"git.shnyr.tech/loyalty/loyalty/internal/referrals"
	"git.shnyr.tech/loyalty/loyalty/internal/worker"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	if err := run(log); err != nil {
		log.Error("loyalty stopped", "err", err)
		os.Exit(1)
	}
}

func run(log *slog.Logger) error {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	dir := os.Getenv("LOYALTY_CONFIG_DIR")
	if dir == "" {
		dir = "config"
	}
	app, err := config.LoadApp(filepath.Join(dir, "app.yaml"))
	if err != nil {
		return err
	}
	consumersCfg, err := config.LoadConsumers(filepath.Join(dir, "consumers.yaml"))
	if err != nil {
		return err
	}
	workersCfg, err := config.LoadWorkers(filepath.Join(dir, "workers.yaml"))
	if err != nil {
		return err
	}

	db, err := postgres.Connect(ctx, app.Database.DSN, app.Database.PoolSize)
	if err != nil {
		return fmt.Errorf("postgres: %w", err)
	}
	defer db.Close()

	serviceToken := os.Getenv("LOYALTY_SERVICE_TOKEN")
	payouts := payoutgateway.New(app.Clients.PayoutGateway.BaseURL, serviceToken, app.Clients.PayoutGateway.Timeout)
	notify := notifications.New(app.Clients.Notifications.BaseURL, serviceToken, app.Clients.Notifications.Timeout)

	discountSvc := discounts.NewService(discounts.NewPostgresRepository(db), notify, app.Discounts.Compensation, log)
	referralSvc := referrals.NewService(referrals.NewPostgresRepository(db), payouts, log)
	campaignSvc := campaigns.NewService(campaigns.NewPostgresRepository(db), discountSvc, referralSvc, app.Promo.MaxPerMonth, log)
	promoSvc := promo.NewService(campaignSvc, log)

	router := chi.NewRouter()
	router.Use(middleware.RequestID, middleware.RealIP, middleware.Recoverer)
	router.Get("/healthz", func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusOK) })
	router.Mount("/v1", httpapi.NewHandler(promoSvc, discountSvc, referralSvc, os.Getenv("LOYALTY_ORDERS_TOKEN"), log).Routes())
	router.Mount("/admin/v1", admin.NewHandler(campaignSvc, discountSvc, referralSvc, app.Discounts.Compensation.Role, log).Routes())

	srv := &http.Server{
		Addr:              fmt.Sprintf(":%d", app.HTTP.Port),
		Handler:           router,
		ReadHeaderTimeout: 5 * time.Second,
	}

	g, ctx := errgroup.WithContext(ctx)
	g.Go(func() error {
		log.Info("http server started", "addr", srv.Addr)
		if err := srv.ListenAndServe(); !errors.Is(err, http.ErrServerClosed) {
			return err
		}
		return nil
	})
	g.Go(func() error {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), app.HTTP.ShutdownGrace)
		defer cancel()
		return srv.Shutdown(shutdownCtx)
	})

	handlers := map[string]kafkaconsumer.Handler{
		"payment_succeeded": consumers.NewPaymentSucceeded(campaignSvc, log),
		"user_registered":   consumers.NewUserRegistered(campaignSvc, log),
	}
	for _, cc := range consumersCfg.Consumers {
		h, ok := handlers[cc.Handler]
		if !ok {
			return fmt.Errorf("consumer %s: unknown handler %q", cc.Name, cc.Handler)
		}
		eff, err := consumersCfg.Effective(cc)
		if err != nil {
			return err
		}
		c := kafkaconsumer.New(kafkaconsumer.Config{
			Brokers:           consumersCfg.Kafka.Brokers(),
			Topic:             eff.Topic,
			Group:             eff.Group,
			RetryTopic:        eff.Retry.Topic,
			DLQTopic:          eff.Retry.DLQ,
			StartOffset:       startOffset(eff.AutoOffsetReset),
			SessionTimeout:    eff.SessionTimeout,
			CommitInterval:    eff.CommitInterval,
			Concurrency:       eff.Concurrency,
			ProcessingTimeout: eff.ProcessingTimeout,
			MaxAttempts:       eff.MaxAttempts,
			Backoff:           eff.Backoff,
		}, h, log.With("consumer", cc.Name))
		g.Go(func() error { return c.Run(ctx) })
	}

	jobs := map[string]func(ctx context.Context, batchSize int) error{
		"reward-payouts": referralSvc.PayPending,
	}
	for _, w := range workersCfg.Workers {
		job, ok := jobs[w.Name]
		if !ok {
			return fmt.Errorf("unknown worker %q", w.Name)
		}
		interval, err := w.Interval()
		if err != nil {
			return err
		}
		g.Go(func() error {
			return worker.Run(ctx, w.Name, interval, w.RunOnStart, func(ctx context.Context) error {
				return job(ctx, w.BatchSize)
			}, log)
		})
	}

	return g.Wait()
}

func startOffset(autoOffsetReset string) int64 {
	if autoOffsetReset == "earliest" {
		return kafka.FirstOffset
	}
	return kafka.LastOffset
}
