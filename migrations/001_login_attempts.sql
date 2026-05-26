-- ═══════════════════════════════════════════════════════════════
--  migrations/001_login_attempts.sql
--  Run once against parisar_db to enable login rate limiting.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `ip_address`      VARCHAR(45)      NOT NULL,
    `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `locked_until`    DATETIME         NULL     DEFAULT NULL,
    `lockout_count`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_attempt_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ip` (`ip_address`),
    KEY `idx_locked_until` (`locked_until`),
    KEY `idx_last_attempt` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
