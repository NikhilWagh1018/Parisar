-- ═══════════════════════════════════════════════════════════════
--  019_password_resets.sql
--  One-time password reset links. Only a SHA-256 hash of each token is
--  stored, so a copy of this table cannot be used to reset anyone's password.
--
--  user_id has no foreign key on purpose: production users.id is a signed
--  INT while older migrations declare it unsigned, and a mismatch makes
--  MySQL refuse the constraint. Rows are short-lived and pruned in code.
--
--  Run on Railway (MySQL service → Database → Data → Query).
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT             NOT NULL,
    `token_hash`   CHAR(64)        NOT NULL,
    `expires_at`   DATETIME        NOT NULL,
    `used_at`      DATETIME        NULL,
    `created_at`   DATETIME        NOT NULL,
    `requested_ip` VARCHAR(45)     NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_resets_token` (`token_hash`),
    KEY `idx_password_resets_user` (`user_id`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
