-- ═══════════════════════════════════════════════════════════════
--  migrations/003_unique_road_name.sql
--  Adds a UNIQUE constraint so one user cannot create duplicate
--  road names. Prevents the "SWAMI VIVEKANAD ROAD ×4" problem
--  visible on the dashboard.
--
--  Run once on your Railway MySQL instance:
--    mysql -h $MYSQLHOST -u $MYSQLUSER -p$MYSQLPASSWORD $MYSQLDATABASE < 003_unique_road_name.sql
--
--  NOTE: If you already have duplicate road names in the DB, you
--  must deduplicate or delete them BEFORE running this migration,
--  otherwise the ALTER TABLE will fail with a duplicate-key error.
--  Use this query to find duplicates first:
--
--    SELECT creator_id, name, COUNT(*) AS cnt
--      FROM roads
--     GROUP BY creator_id, name
--    HAVING cnt > 1;
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE roads
    ADD CONSTRAINT uq_road_creator_name
    UNIQUE KEY (creator_id, name);
