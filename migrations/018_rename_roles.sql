-- ═══════════════════════════════════════════════════════════════
--  migrations/018_rename_roles.sql
--  Stored role values now match what people see in the app:
--      national_admin  ->  admin         (shown as "Admin")
--      city_admin      ->  city_leader   (shown as "City Leader")
--      surveyor        ->  surveyor      (unchanged)
--
--  Run MANUALLY in the Railway MySQL console, ONE statement per query.
--  Take a backup first. Do the steps in this order:
--
--    STEP 1  before merging the code   (old code keeps working)
--    STEP 2  right after Railway shows the new deploy as Active
--    STEP 3  after step 2 is verified
--    STEP 4  optional, tidies old log entries
--
--  Between the deploy and STEP 2 an Admin whose row still says
--  national_admin cannot open admin pages. The database console is
--  independent of the app, so STEP 2 can always be run.
-- ═══════════════════════════════════════════════════════════════

-- Check the real definition first; STEP 1 assumes exactly these three values:
-- SHOW COLUMNS FROM users LIKE 'role';
-- SELECT role, COUNT(*) FROM users GROUP BY role;

-- ── STEP 1: accept both the old and the new values ──────────────
ALTER TABLE users
    MODIFY role ENUM('surveyor','city_admin','national_admin','city_leader','admin')
    NOT NULL DEFAULT 'surveyor';

-- ── STEP 2: move every row to the new values ────────────────────
UPDATE users SET role = 'admin'       WHERE role = 'national_admin';
UPDATE users SET role = 'city_leader' WHERE role = 'city_admin';

-- Verify: only surveyor / city_leader / admin should appear.
-- SELECT role, COUNT(*) FROM users GROUP BY role;

-- ── STEP 3: drop the old values ─────────────────────────────────
ALTER TABLE users
    MODIFY role ENUM('surveyor','city_leader','admin')
    NOT NULL DEFAULT 'surveyor';

-- ── STEP 4 (optional): old activity-log entries that stored a role name ──
UPDATE activity_log
   SET meta = JSON_SET(meta, '$.to_role', 'city_leader')
 WHERE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.to_role')) = 'city_admin';
UPDATE activity_log
   SET meta = JSON_SET(meta, '$.to_role', 'admin')
 WHERE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.to_role')) = 'national_admin';
UPDATE activity_log
   SET meta = JSON_SET(meta, '$.from_role', 'city_leader')
 WHERE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.from_role')) = 'city_admin';
UPDATE activity_log
   SET meta = JSON_SET(meta, '$.from_role', 'admin')
 WHERE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.from_role')) = 'national_admin';

-- ── ROLLBACK (only if something goes wrong; reverse of the above) ──
-- ALTER TABLE users MODIFY role ENUM('surveyor','city_admin','national_admin','city_leader','admin') NOT NULL DEFAULT 'surveyor';
-- UPDATE users SET role = 'national_admin' WHERE role = 'admin';
-- UPDATE users SET role = 'city_admin'     WHERE role = 'city_leader';
-- then redeploy the previous commit.
