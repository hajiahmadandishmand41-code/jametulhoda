-- Additive, repeatable MySQL/MariaDB migration. No existing data is changed.
CREATE TABLE IF NOT EXISTS content_view_receipts (
    post_id INTEGER NOT NULL,
    visitor_hash VARCHAR(64) NOT NULL,
    view_day DATE NOT NULL,
    PRIMARY KEY (post_id, visitor_hash, view_day)
) ENGINE=InnoDB;
-- Rollback, only after disabling view recording:
-- DROP TABLE content_view_receipts;
-- Existing posts and view_count values remain intact.
