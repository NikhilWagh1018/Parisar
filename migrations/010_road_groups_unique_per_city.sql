-- ═══════════════════════════════════════════════════════════════
--  010_road_groups_unique_per_city.sql
--  Road names are unique per city, not globally, so a second city
--  can have its own "KARVE ROAD".
--
--  ALREADY APPLIED to the live Railway DB (Oct 1, 2026). Kept here
--  so a fresh database ends up with the same keys. Only run it on
--  a database that still has uq_road_groups_name.
--
--  Safe on data that satisfied the old (stricter) global key.
--  The existing idx_road_groups_city_id and the fk_road_groups_city
--  foreign key are untouched; the new key also starts with city_id.
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE road_groups
    DROP INDEX uq_road_groups_name,
    ADD UNIQUE KEY uq_road_groups_city_name (city_id, canonical_name);
