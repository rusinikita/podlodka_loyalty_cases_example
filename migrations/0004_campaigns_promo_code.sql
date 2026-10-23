ALTER TABLE campaigns ADD COLUMN promo_code text;

CREATE UNIQUE INDEX campaigns_promo_code_key ON campaigns (promo_code) WHERE promo_code IS NOT NULL;
