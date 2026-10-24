// Package worker runs periodic jobs inside the service process.
package worker

import (
	"context"
	"log/slog"
	"time"
)

type Job func(ctx context.Context) error

// Run calls job every interval until ctx is cancelled. With runOnStart the
// first run happens right away, otherwise after the first interval. A failed
// run is logged; the next one happens on schedule.
func Run(ctx context.Context, name string, interval time.Duration, runOnStart bool, job Job, log *slog.Logger) error {
	log = log.With("worker", name)
	if runOnStart {
		runOnce(ctx, job, log)
	}

	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return nil
		case <-ticker.C:
			runOnce(ctx, job, log)
		}
	}
}

func runOnce(ctx context.Context, job Job, log *slog.Logger) {
	start := time.Now()
	if err := job(ctx); err != nil {
		if ctx.Err() == nil {
			log.ErrorContext(ctx, "worker run failed", "err", err, "took", time.Since(start))
		}
		return
	}
	log.InfoContext(ctx, "worker run finished", "took", time.Since(start))
}
