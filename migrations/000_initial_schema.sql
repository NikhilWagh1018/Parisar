-- ═══════════════════════════════════════════════════════════════
--  migrations/000_initial_schema.sql
--  Full base schema for Parisar CycleAudit.
--  Run this ONCE on a fresh database before any other migrations.
--  Safe to inspect on existing DBs — all statements use IF NOT EXISTS.
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── users ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `public_id`       VARCHAR(20)      NOT NULL DEFAULT '',
    `name`            VARCHAR(100)     NOT NULL,
    `email`           VARCHAR(150)     NOT NULL,
    `phone`           VARCHAR(20)      NULL,
    `organisation`    VARCHAR(150)     NULL,
    `gender`          VARCHAR(20)      NULL,
    `age`             TINYINT UNSIGNED NULL,
    `password`        VARCHAR(255)     NULL     COMMENT 'NULL for OAuth-only accounts',
    `role`            ENUM('surveyor','admin') NOT NULL DEFAULT 'surveyor',
    `auth_provider`   ENUM('local','google')   NOT NULL DEFAULT 'local',
    `google_id`       VARCHAR(100)     NULL,
    `profile_picture` LONGTEXT         NULL,
    `email_verified`  TINYINT(1)       NOT NULL DEFAULT 0,
    `last_login`      DATETIME         NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email`     (`email`),
    UNIQUE KEY `uq_public_id` (`public_id`),
    KEY `idx_role`            (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── roads ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roads` (
    `id`             INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `public_id`      VARCHAR(20)        NOT NULL DEFAULT '',
    `creator_id`     INT UNSIGNED       NOT NULL,
    `name`           VARCHAR(200)       NOT NULL,
    `start_point`    VARCHAR(200)       NULL,
    `end_point`      VARCHAR(200)       NULL,
    `total_length`   DECIMAL(10,2)      NULL     COMMENT 'metres',
    `gps_start`      VARCHAR(100)       NULL,
    `gps_end`        VARCHAR(100)       NULL,
    `segment_method` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    `segment_length` DECIMAL(10,2)      NULL,
    `created_at`     DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_public_id`  (`public_id`),
    UNIQUE KEY `uq_road_name`  (`name`),
    KEY `idx_creator`          (`creator_id`),
    CONSTRAINT `fk_roads_creator`
        FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── segments ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `segments` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `public_id`      VARCHAR(20)     NOT NULL DEFAULT '',
    `road_id`        INT UNSIGNED    NOT NULL,
    `segment_number` SMALLINT        NOT NULL,
    `start_label`    VARCHAR(200)    NULL,
    `end_label`      VARCHAR(200)    NULL,
    `start_distance` DECIMAL(10,2)   NOT NULL DEFAULT 0,
    `end_distance`   DECIMAL(10,2)   NOT NULL DEFAULT 0,
    `length`         DECIMAL(10,2)   NOT NULL DEFAULT 0,
    `status`         ENUM('pending','completed') NOT NULL DEFAULT 'pending',
    `completed_at`   DATETIME        NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_public_id`       (`public_id`),
    KEY `idx_road_id`               (`road_id`),
    KEY `idx_road_segment_number`   (`road_id`, `segment_number`),
    CONSTRAINT `fk_segments_road`
        FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── audit_sessions ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_sessions` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id`    VARCHAR(20)  NOT NULL DEFAULT '',
    `user_id`      INT UNSIGNED NOT NULL,
    `road_id`      INT UNSIGNED NOT NULL,
    `status`       ENUM('active','completed','abandoned') NOT NULL DEFAULT 'active',
    `started_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME     NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_public_id` (`public_id`),
    KEY `idx_user_id`         (`user_id`),
    KEY `idx_road_id`         (`road_id`),
    CONSTRAINT `fk_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_sessions_road`
        FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── segment_audits ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `segment_audits` (
    `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `public_id`          VARCHAR(20)   NOT NULL DEFAULT '',
    `session_id`         INT UNSIGNED  NOT NULL,
    `segment_id`         INT UNSIGNED  NOT NULL,
    `surveyor_id`        INT UNSIGNED  NOT NULL,
    `surface_type`       VARCHAR(100)  NULL,
    `surface_material`   VARCHAR(100)  NULL,
    `people_walking`     VARCHAR(50)   NULL,
    `signage_count`      SMALLINT      NOT NULL DEFAULT 0,
    `shade`              VARCHAR(50)   NULL,
    `light_after_sunset` VARCHAR(50)   NULL,
    `track_geometry`     VARCHAR(100)  NULL,
    `buffer_zone`        VARCHAR(50)   NULL,
    `segment_width`      DECIMAL(6,2)  NULL,
    `segment_length`     DECIMAL(10,2) NULL,
    `comments`           TEXT          NULL,
    `surface_issues`     JSON          NULL,
    `overhead_issues`    JSON          NULL,
    `footpath_rating`    JSON          NULL,
    `footpath_score`     TINYINT       NOT NULL DEFAULT 0,
    `audited_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_public_id`   (`public_id`),
    KEY `idx_segment_id`        (`segment_id`),
    KEY `idx_session_id`        (`session_id`),
    KEY `idx_surveyor_id`       (`surveyor_id`),
    CONSTRAINT `fk_audits_segment`
        FOREIGN KEY (`segment_id`)  REFERENCES `segments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_audits_session`
        FOREIGN KEY (`session_id`)  REFERENCES `audit_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_audits_surveyor`
        FOREIGN KEY (`surveyor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── obstructions ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `obstructions` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id`              VARCHAR(20)  NOT NULL DEFAULT '',
    `audit_id`               INT UNSIGNED NOT NULL,
    `obstruction_category`   ENUM('fixed','movable','parked') NOT NULL,
    `obstruction_type`       VARCHAR(100) NOT NULL,
    `cyclist_slowed`         TINYINT      NOT NULL DEFAULT 0,
    `partial_obstructions`   TINYINT      NOT NULL DEFAULT 0,
    `total_obstructions`     TINYINT      NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_public_id` (`public_id`),
    KEY `idx_audit_id`        (`audit_id`),
    CONSTRAINT `fk_obs_audit`
        FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── intersections ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `intersections` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `audit_id`         INT UNSIGNED NOT NULL,
    `intersection_num` SMALLINT     NOT NULL,
    `gps_coords`       VARCHAR(100) NULL,
    `landmark_name`    VARCHAR(200) NULL,
    `off_ramp`         VARCHAR(50)  NULL,
    `on_ramp`          VARCHAR(50)  NULL,
    `markings`         VARCHAR(50)  NULL,
    `signage`          VARCHAR(50)  NULL,
    `traffic_calming`  VARCHAR(50)  NULL,
    `discontinuity`    VARCHAR(100) NULL,
    `tapering`         VARCHAR(100) NULL,
    `obstruction_type` VARCHAR(100) NULL,

    PRIMARY KEY (`id`),
    KEY `idx_audit_id` (`audit_id`),
    CONSTRAINT `fk_int_audit`
        FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- ── Note ───────────────────────────────────────────────────────
-- After running this file, run migrations 001 through 004 in order.
-- 001_login_attempts.sql
-- 002_activity_log.sql
-- 003_unique_road_name.sql
-- 004_avatar_longtext.sql
