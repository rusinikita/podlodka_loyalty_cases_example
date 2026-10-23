CREATE TABLE referral_rewards (
    id           bigserial PRIMARY KEY,
    campaign_id  bigint      NOT NULL REFERENCES campaigns (id),
    user_id      bigint      NOT NULL,
    invitee_id   bigint      NOT NULL,
    amount       integer     NOT NULL,
    status       smallint    NOT NULL,
    payout_id    uuid,
    created_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT referral_rewards_invitee_key UNIQUE (campaign_id, invitee_id)
);

CREATE INDEX referral_rewards_status_idx ON referral_rewards (status);
