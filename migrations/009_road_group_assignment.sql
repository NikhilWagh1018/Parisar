-- ═══════════════════════════════════════════════════════════════
--  009_road_group_assignment.sql
--  Confirmed with Tanzeel (Sep 30): "The city leader does not create
--  segments, they only add roads and assign them to the surveyors."
--  No segment locking (confirmed same thread — not built).
--
--  Adding a road via the admin Roads page (api/admin/roads.php,
--  action=create) only ever inserts a road_groups row — the member
--  `roads` rows (actual audit sessions) are created later by a
--  surveyor self-service, via pages/segment.php. So "assign a road
--  to a surveyor" is naturally a property of the road_groups row
--  (the real-world road), not of any one `roads` session row.
--
--  NOTE: road_groups itself predates migration tracking (created
--  directly on the live DB, like `cities` — see context notes) so
--  its full existing schema is intentionally not assumed here;
--  this only adds new columns.
--
--  Column type confirmed against live DB via DESCRIBE users —
--  users.id is plain INT (signed), not INT UNSIGNED as the oldest
--  tracked migration (000_initial_schema.sql) implied; that schema
--  predates the untracked Phase 2 role restructuring. FK columns
--  below match the live type exactly.
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE road_groups
    ADD COLUMN assigned_surveyor_id INT NULL AFTER city_id,
    ADD COLUMN assigned_at          DATETIME     NULL AFTER assigned_surveyor_id,
    ADD COLUMN assigned_by          INT NULL AFTER assigned_at,
    ADD KEY idx_road_groups_assigned_surveyor (assigned_surveyor_id),
    ADD CONSTRAINT fk_road_groups_assigned_surveyor
        FOREIGN KEY (assigned_surveyor_id) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_road_groups_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL;
