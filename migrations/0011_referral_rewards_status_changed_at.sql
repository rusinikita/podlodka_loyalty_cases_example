ALTER TABLE referral_rewards ADD COLUMN status_changed_at timestamptz NOT NULL DEFAULT now();

DROP INDEX CONCURRENTLY IF EXISTS referral_rewards_status_idx;
CREATE INDEX CONCURRENTLY referral_rewards_status_changed_idx ON referral_rewards (status, status_changed_at);
