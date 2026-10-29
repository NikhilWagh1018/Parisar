-- ═══════════════════════════════════════════════════════════════
--  020_users_home_city_state.sql
--  Where a person says they are: the City and State asked for at sign-up.
--  This is information for the Admin (who assigns City Leaders and moves
--  people into a city); it does not give anyone access to a city.
--  users.city_id still decides which city's data a person can see.
--
--  Both columns are NULL-able, so existing accounts keep working.
--  RUN THIS BEFORE the code that uses it is deployed.
--
--  Railway: MySQL service → Database → Data → Query. Run each statement on
--  its own and confirm the "destructive action" prompt.
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE `users` ADD COLUMN `home_city` VARCHAR(100) NULL DEFAULT NULL;

ALTER TABLE `users` ADD COLUMN `home_state` VARCHAR(100) NULL DEFAULT NULL;
