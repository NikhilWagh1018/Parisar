-- ═══════════════════════════════════════════════════════════════
--  migrations/000_initial_schema.sql
--  COMPLETE base schema for the Parisar CycleAudit system.
--
--  Run this ONCE on a fresh database to create all tables.
--  Then run migrations 001–005 in order for any incremental changes.
--
--  Usage (Railway MySQL):
--    mysql -h $MYSQLHOST -u $MYSQLUSER -p$MYSQLPASSWORD $MYSQLDATABASE \
--          < migrations/000_initial_schema.sql
--
--  Tables:
--    users               — registered surveyors and admins
--    roads               — cycle track roads defined by surveyors
--    segments            — individual road segments
--    audit_sessions      — one session per user per road audit run
--    segment_audits      — core audit data per segment
--    obstructions        — obstruction counts per audit
--    intersections       — per-intersection data per audit
--    login_attempts      — IP-based login rate limiting (see 001)
--    activity_log        — audit trail of user actions (see 002)
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── users ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `public_id`       VARCHAR(16)      NOT NULL DEFAULT '',
    `name`            VARCHAR(100)     NOT NULL,
    `email`           VARCHAR(255)     NOT NULL,
    `password`        VARCHAR(255)     NULL     DEFAULT NULL,
    `role`            ENUM('admin','surveyor') NOT NULL DEFAULT 'surveyor',
    `organisation`    VARCHAR(150)     NULL     DEFAULT NULL,
    `profile_picture` LONGTEXT         NULL     DEFAULT NULL,
    `auth_provider`   VARCHAR(20)      NOT NULL DEFAULT 'local',
    `google_id`       VARCHAR(64)      NULL     DEFAULT NULL,
    `last_login`      DATETIME         NULL     DEFAULT NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email`     (`email`),
    UNIQUE KEY `uq_public_id` (`public_id`),
    KEY `idx_role`            (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── roads ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roads` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `public_id`    VARCHAR(16)   NOT NULL DEFAULT '',
    `name`         VARCHAR(255)  NOT NULL,
    `start_point`  VARCHAR(255)  NULL     DEFAULT NULL,
    `end_point`    VARCHAR(255)  NULL     DEFAULT NULL,
    `total_length` DECIMAL(8,2)  NULL     DEFAULT NULL,  -- metres
    `creator_id`   INT UNSIGNED  NOT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_road_public_id`     (`public_id`),
    UNIQUE KEY `uq_road_creator_name`  (`creator_id`, `name`),
    KEY `idx_roads_creator_id`         (`creator_id`),

    CONSTRAINT `fk_roads_creator`
        FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── segments ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `segments` (
    `id`             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `public_id`      VARCHAR(16)    NOT NULL DEFAULT '',
    `road_id`        INT UNSIGNED   NOT NULL,
    `segment_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `start_label`    VARCHAR(255)   NULL     DEFAULT NULL,
    `end_label`      VARCHAR(255)   NULL     DEFAULT NULL,
    `length`         DECIMAL(8,2)   NOT NULL DEFAULT 500.00,  -- metres
    `status`         ENUM('pending','completed') NOT NULL DEFAULT 'pending',
    `completed_at`   DATETIME       NULL     DEFAULT NULL,
    `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_segment_public_id` (`public_id`),
    KEY `idx_seg_road_id`             (`road_id`),
    KEY `idx_seg_status`              (`status`),

    CONSTRAINT `fk_segments_road`
        FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── audit_sessions ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_sessions` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id`    VARCHAR(16)  NOT NULL DEFAULT '',
    `road_id`      INT UNSIGNED NOT NULL,
    `user_id`      INT UNSIGNED NOT NULL,
    `status`       ENUM('active','completed') NOT NULL DEFAULT 'active',
    `started_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME     NULL     DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_public_id` (`public_id`),
    KEY `idx_as_user_id`              (`user_id`),
    KEY `idx_as_road_id`              (`road_id`),
    KEY `idx_as_status`               (`status`),

    CONSTRAINT `fk_sessions_road`
        FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── segment_audits ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `segment_audits` (
    `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `public_id`           VARCHAR(16)   NOT NULL DEFAULT '',
    `session_id`          INT UNSIGNED  NOT NULL,
    `segment_id`          INT UNSIGNED  NOT NULL,
    `surveyor_id`         INT UNSIGNED  NOT NULL,

    -- Landmarks & GPS
    `start_landmark`      VARCHAR(255)  NULL DEFAULT NULL,
    `end_landmark`        VARCHAR(255)  NULL DEFAULT NULL,
    `gps_start`           VARCHAR(100)  NULL DEFAULT NULL,
    `gps_end`             VARCHAR(100)  NULL DEFAULT NULL,

    -- Track presence
    `cycle_track_missing` VARCHAR(10)   NULL DEFAULT NULL,
    `missing_length`      DECIMAL(8,2)  NULL DEFAULT NULL,

    -- Qualitative fields
    `cyclist_use`         VARCHAR(50)   NULL DEFAULT NULL,
    `better_surface`      VARCHAR(10)   NULL DEFAULT NULL,
    `surface_material`    VARCHAR(50)   NULL DEFAULT NULL,
    `people_walking`      VARCHAR(50)   NULL DEFAULT NULL,
    `signage_count`       INT           NOT NULL DEFAULT 0,
    `shade`               VARCHAR(20)   NULL DEFAULT NULL,
    `light_after_sunset`  VARCHAR(20)   NULL DEFAULT NULL,
    `track_geometry`      VARCHAR(50)   NULL DEFAULT NULL,
    `buffer_zone`         VARCHAR(50)   NULL DEFAULT NULL,
    `segment_width`       VARCHAR(50)   NULL DEFAULT NULL,
    `segment_length`      VARCHAR(50)   NULL DEFAULT NULL,
    `comments`            TEXT          NULL DEFAULT NULL,

    -- JSON multi-select fields
    `surface_issues`      JSON          NULL DEFAULT NULL,
    `overhead_issues`     JSON          NULL DEFAULT NULL,
    `footpath_rating`     JSON          NULL DEFAULT NULL,
    `footpath_score`      TINYINT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_audit_public_id` (`public_id`),
    KEY `idx_sa_segment_id`         (`segment_id`),
    KEY `idx_sa_session_id`         (`session_id`),
    KEY `idx_sa_surveyor_id`        (`surveyor_id`),

    CONSTRAINT `fk_sa_segment`
        FOREIGN KEY (`segment_id`)  REFERENCES `segments` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_sa_session`
        FOREIGN KEY (`session_id`)  REFERENCES `audit_sessions` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_sa_surveyor`
        FOREIGN KEY (`surveyor_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── obstructions ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `obstructions` (
    `id`                    INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `public_id`             VARCHAR(16)      NOT NULL DEFAULT '',
    `audit_id`              INT UNSIGNED     NOT NULL,
    `obstruction_category`  VARCHAR(20)      NOT NULL,  -- fixed|movable|parked
    `obstruction_type`      VARCHAR(50)      NOT NULL,
    `cyclist_slowed`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `partial_obstructions`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_obstructions`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_obs_public_id` (`public_id`),
    KEY `idx_obs_audit_id`        (`audit_id`),

    CONSTRAINT `fk_obs_audit`
        FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── intersections ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `intersections` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id`        VARCHAR(16)  NOT NULL DEFAULT '',
    `audit_id`         INT UNSIGNED NOT NULL,
    `intersection_num` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `gps_coords`       VARCHAR(100) NULL DEFAULT NULL,
    `landmark_name`    VARCHAR(255) NULL DEFAULT NULL,
    `off_ramp`         VARCHAR(50)  NULL DEFAULT NULL,
    `on_ramp`          VARCHAR(50)  NULL DEFAULT NULL,
    `markings`         VARCHAR(50)  NULL DEFAULT NULL,
    `signage`          VARCHAR(50)  NULL DEFAULT NULL,
    `traffic_calming`  VARCHAR(50)  NULL DEFAULT NULL,
    `discontinuity`    VARCHAR(50)  NULL DEFAULT NULL,
    `tapering`         VARCHAR(50)  NULL DEFAULT NULL,
    `obstruction_type` VARCHAR(100) NULL DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_int_public_id` (`public_id`),
    KEY `idx_int_audit_id`        (`audit_id`),

    CONSTRAINT `fk_int_audit`
        FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── login_attempts ────────────────────────────────────────────
-- (also in 001_login_attempts.sql — included here for fresh setups)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `ip_address`      VARCHAR(45)      NOT NULL,
    `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `locked_until`    DATETIME         NULL     DEFAULT NULL,
    `lockout_count`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_attempt_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ip`          (`ip_address`),
    KEY `idx_locked_until`      (`locked_until`),
    KEY `idx_last_attempt`      (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── activity_log ─────────────────────────────────────────────
-- (also in 002_activity_log.sql — included here for fresh setups)
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED    NULL     DEFAULT NULL,
    `action`     VARCHAR(64)     NOT NULL,
    `meta`       JSON            NULL     DEFAULT NULL,
    `ip_address` VARCHAR(45)     NOT NULL DEFAULT '',
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_user_id`    (`user_id`),
    KEY `idx_action`     (`action`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
