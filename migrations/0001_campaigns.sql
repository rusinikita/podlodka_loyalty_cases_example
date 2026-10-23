CREATE TABLE campaigns (
    id             bigserial PRIMARY KEY,
    slug           text        NOT NULL,
    trigger        text        NOT NULL,
    conditions     jsonb       NOT NULL DEFAULT '{}',
    reward_type    text        NOT NULL,
    reward_params  jsonb       NOT NULL DEFAULT '{}',
    starts_at      timestamptz NOT NULL,
    ends_at        timestamptz,
    is_active      boolean     NOT NULL DEFAULT true,
    created_at     timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT campaigns_slug_key UNIQUE (slug)
);
