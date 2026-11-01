-- ---------------------------------------------------------------
--  027_team_leader.sql
--  Each City Leader chooses their own team of surveyors. A surveyor's
--  "forgot password" request is then shown only to that surveyor's team
--  leader. A surveyor in no team (NULL) is shown to every City Leader of
--  their city, so a request is never left without someone to answer it.
--
--  Railway: MySQL service > Database > Data > Query. Run the statements one at
--  a time (the query box accepts one statement per run), in order, BEFORE the
--  code that uses them is deployed.
-- ---------------------------------------------------------------

-- 1 of 2
ALTER TABLE `users` ADD COLUMN `team_leader_id` INT NULL AFTER `city_id`;

-- 2 of 2
ALTER TABLE `users` ADD KEY `idx_users_team_leader` (`team_leader_id`);
