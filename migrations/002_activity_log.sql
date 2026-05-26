-- ═══════════════════════════════════════════════════════════════
--  migrations/002_activity_log.sql
--  Creates the activity_log table for Parisar.
--  Run once: SOURCE this file in phpMyAdmin or MySQL CLI.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS activity_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT             NULL,          -- NULL = anonymous / pre-login
    action     VARCHAR(64)     NOT NULL,      -- e.g. 'segment_submitted'
    meta       JSON            NULL,          -- optional extra data
    ip_address VARCHAR(45)     NOT NULL DEFAULT '',
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_user_id   (user_id),
    INDEX idx_action    (action),
    INDEX idx_created_at(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
