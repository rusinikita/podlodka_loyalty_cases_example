CREATE INDEX CONCURRENTLY user_discounts_user_used_idx ON user_discounts (user_id, used_at) WHERE used_at IS NOT NULL;
