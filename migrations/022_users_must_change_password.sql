-- ---------------------------------------------------------------
--  022_users_must_change_password.sql
--  An Admin or City Leader can reset a password with a temporary one.
--  This flag makes the person choose their own at the next sign-in.
--
--  Railway: MySQL service > Database > Data > Query. Run it once, before
--  the code that uses it is deployed.
-- ---------------------------------------------------------------

ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password`;
