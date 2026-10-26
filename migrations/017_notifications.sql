-- ═══════════════════════════════════════════════════════════════
--  017_notifications.sql
--  In-app notifications. First use: a surveyor is told when a City
--  Leader assigns them a road or segment.
--  Additions only: nothing existing is changed or deleted.
--  Notifications are removed automatically if their user is deleted.
--
--  user_id must have exactly the same type as users.id or the foreign key
--  fails ("incompatible"). In production users.id is a signed INT, so
--  user_id is INT here. Check first if unsure:
--      SHOW COLUMNS FROM users LIKE 'id';
--
--  Railway's query console runs ONE statement per query. This file has one.
--
--  Rollback (notifications are lost):
--    DROP TABLE notifications;
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT          NOT NULL,
  type       VARCHAR(40)  NOT NULL,
  title      VARCHAR(150) NOT NULL,
  body       VARCHAR(500) NOT NULL DEFAULT '',
  link       VARCHAR(200) NULL,
  is_read    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_unread (user_id, is_read, id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
