CREATE INDEX CONCURRENTLY user_discounts_expiry_idx ON user_discounts (expires_at) WHERE used_at IS NULL;
