ALTER TABLE user_discounts ALTER COLUMN campaign_id DROP NOT NULL;

ALTER TABLE user_discounts
    ADD COLUMN created_by text,
    ADD COLUMN comment    text;
