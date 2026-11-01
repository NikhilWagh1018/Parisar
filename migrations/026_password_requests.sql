-- ---------------------------------------------------------------
--  026_password_requests.sql
--  "Forgot password" without email. The person picks a new password and gets
--  a 6-digit code on screen. Their City Leader (or the Admin, for City
--  Leaders and for people with no city) phones them, checks it is really them,
--  asks for the code, and approves. Only then does the new password start
--  working.
--
--  new_password_hash is a normal password_hash() of the password the person
--  chose, and code_hash is the SHA-256 of the 6-digit code. Neither is ever
--  shown to anyone.
--
--  users.password_changed_at lets the site sign out every older session of a
--  person whose password was changed by an approval.
--
--  Railway: MySQL service > Database > Data > Query. Run both statements once,
--  in order, before the code that uses them is deployed.
-- ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `password_requests` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED  NOT NULL,
    `approver_role`     VARCHAR(20)   NOT NULL,            -- 'city_leader' or 'admin'
    `city_id`           INT           NULL,                -- the person's city when it was asked
    `new_password_hash` VARCHAR(255)  NOT NULL,
    `code_hash`         CHAR(64)      NOT NULL,
    `code_tries`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `status`            VARCHAR(12)   NOT NULL DEFAULT 'pending',  -- pending, approved, dismissed, replaced, locked
    `requested_ip`      VARCHAR(45)   NOT NULL DEFAULT '',
    `handled_by`        INT UNSIGNED  NULL,
    `handled_at`        DATETIME      NULL,
    `created_at`        DATETIME      NOT NULL,
    `expires_at`        DATETIME      NOT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_pr_inbox`  (`status`, `approver_role`, `city_id`, `created_at`),
    KEY `idx_pr_user`   (`user_id`, `created_at`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL AFTER `must_change_password`;
