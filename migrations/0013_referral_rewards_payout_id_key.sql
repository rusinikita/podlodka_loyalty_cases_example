CREATE UNIQUE INDEX CONCURRENTLY referral_rewards_payout_id_key
    ON referral_rewards (payout_id)
    WHERE payout_id IS NOT NULL;
