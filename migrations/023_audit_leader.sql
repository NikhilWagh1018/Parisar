-- ---------------------------------------------------------------
--  023_audit_leader.sql
--  Each project is assigned to ONE City Leader by the Admin. A city can have
--  several City Leaders; each sees only the projects assigned to them.
--  A project with no leader yet (NULL) is visible to the Admin only.
--
--  Railway: MySQL service > Database > Data > Query. Run the statements one at
--  a time, in order, BEFORE the code that uses them is deployed.
-- ---------------------------------------------------------------

-- 1 of 3
ALTER TABLE `city_audits` ADD COLUMN `leader_user_id` INT NULL AFTER `created_by`;

-- 2 of 3
ALTER TABLE `city_audits` ADD KEY `idx_city_audits_leader` (`leader_user_id`);

-- 3 of 3  Existing projects keep working: give each to its city's City Leader
--         when the city has exactly one. Cities with several leaders are left
--         unassigned; the Admin picks the leader on the project's Edit screen.
UPDATE `city_audits` a
   SET a.leader_user_id = (SELECT MIN(u.id) FROM `users` u WHERE u.city_id = a.city_id AND u.role = 'city_leader')
 WHERE a.leader_user_id IS NULL
   AND (SELECT COUNT(*) FROM `users` u WHERE u.city_id = a.city_id AND u.role = 'city_leader') = 1;
