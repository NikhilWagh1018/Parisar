-- ═══════════════════════════════════════════════════════════════
--  021_user_public_id_usr.sql
--  One ID format for every account, whatever its role: USR-0001.
--  Until now sign-ups got "SURV-0001", even when the person was later
--  made a City Leader or Admin.
--
--  Railway: MySQL service → Database → Data → Query. Run it once.
-- ═══════════════════════════════════════════════════════════════

UPDATE `users` SET `public_id` = CONCAT('USR-', SUBSTRING(`public_id`, 6)) WHERE `public_id` LIKE 'SURV-%';
