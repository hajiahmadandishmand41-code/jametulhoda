
-- Additive view deduplication. No existing counter is reset or reinterpreted.
CREATE TABLE IF NOT EXISTS content_view_receipts (
    post_id INTEGER NOT NULL,
    visitor_hash VARCHAR(64) NOT NULL,
    view_day DATE NOT NULL,
    PRIMARY KEY (post_id, visitor_hash, view_day)
);

-- Rollback: DROP TABLE content_view_receipts;
-- This removes only deduplication receipts, not posts or historical counters.
-- Disable view recording before rollback. Apply again before re-enabling it.

-- No public/anonymous policy: application uses its existing server-side DB role.
ALTER TABLE content_view_receipts ENABLE ROW LEVEL SECURITY;
