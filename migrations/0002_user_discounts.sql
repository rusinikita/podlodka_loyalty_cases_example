CREATE TABLE user_discounts (
    id             bigserial PRIMARY KEY,
    user_id        bigint      NOT NULL,
    campaign_id    bigint      NOT NULL REFERENCES campaigns (id),
    payment_id     text,
    percent        smallint,
    amount         integer,
    max_amount     integer,
    expires_at     timestamptz NOT NULL,
    used_at        timestamptz,
    used_order_id  bigint,
    created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX user_discounts_user_idx ON user_discounts (user_id, created_at DESC);
