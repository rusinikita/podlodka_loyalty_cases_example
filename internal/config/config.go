// Package config loads the service configuration from config/*.yaml.
package config

import (
	"fmt"
	"os"
	"strings"
	"time"

	"gopkg.in/yaml.v3"
)

type App struct {
	HTTP      HTTP      `yaml:"http"`
	Database  Database  `yaml:"database"`
	Promo     Promo     `yaml:"promo"`
	Discounts Discounts `yaml:"discounts"`
	Clients   Clients   `yaml:"clients"`
}

type HTTP struct {
	Port          int           `yaml:"port"`
	ShutdownGrace time.Duration `yaml:"shutdown_grace"`
}

type Database struct {
	DSN      string `yaml:"dsn"`
	PoolSize int32  `yaml:"pool_size"`
}

type Promo struct {
	MaxPerMonth int `yaml:"max_per_month"`
}

type Discounts struct {
	Compensation Compensation `yaml:"compensation"`
}

type Compensation struct {
	MaxPercent int    `yaml:"max_percent"`
	TTLDays    int    `yaml:"ttl_days"`
	Role       string `yaml:"role"`
}

type Clients struct {
	PayoutGateway Client `yaml:"payout_gateway"`
	Notifications Client `yaml:"notifications"`
	OrderHistory  Client `yaml:"order_history"`
}

type Client struct {
	BaseURL string        `yaml:"base_url"`
	Timeout time.Duration `yaml:"timeout"`
}

// LoadApp reads app.yaml. ${VAR} references are replaced with environment
// variables.
func LoadApp(path string) (App, error) {
	var cfg App
	if err := load(path, &cfg, true); err != nil {
		return App{}, err
	}
	return cfg, nil
}

type Workers struct {
	Workers []Worker `yaml:"workers"`
}

type Worker struct {
	Name       string `yaml:"name"`
	Schedule   string `yaml:"schedule"`
	RunOnStart bool   `yaml:"run_on_start"`
	BatchSize  int    `yaml:"batch_size"`
}

// Interval parses a schedule of the form "every <duration>".
func (w Worker) Interval() (time.Duration, error) {
	s, ok := strings.CutPrefix(w.Schedule, "every ")
	if !ok {
		return 0, fmt.Errorf("worker %s: unsupported schedule %q", w.Name, w.Schedule)
	}
	d, err := time.ParseDuration(strings.TrimSpace(s))
	if err != nil {
		return 0, fmt.Errorf("worker %s: %w", w.Name, err)
	}
	return d, nil
}

func LoadWorkers(path string) (Workers, error) {
	var cfg Workers
	if err := load(path, &cfg, false); err != nil {
		return Workers{}, err
	}
	return cfg, nil
}

func load(path string, out any, expandEnv bool) error {
	raw, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("read %s: %w", path, err)
	}
	if expandEnv {
		raw = []byte(os.ExpandEnv(string(raw)))
	}
	if err := yaml.Unmarshal(raw, out); err != nil {
		return fmt.Errorf("parse %s: %w", path, err)
	}
	return nil
}
