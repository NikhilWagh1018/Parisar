<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/PastAuditRepository.php
//  Past audits the Admin added by hand (see helpers/PastAudits.php).
//  "Delete" only sets deleted_at: the row and its PDF stay in the database.
//  Plain SQL that runs on MySQL and on SQLite (the tests use SQLite).
// ═══════════════════════════════════════════════════════════════

class PastAuditRepository
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string,mixed> $clean from pastAuditValidate() */
    public function create(array $clean, int $adminId): int
    {
        $data = null;
        $name = null;
        if ($clean['report'] !== null) {
            $data = (string)file_get_contents((string)$clean['report']['tmp']);
            $name = (string)$clean['report']['name'];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO past_audits
               (city_name, state, name, audit_date, road_count, segment_count, length_km, score, condition_label,
                report_name, report_data, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $clean['city_name']);
        $stmt->bindValue(2, $clean['state']);
        $stmt->bindValue(3, $clean['name']);
        $stmt->bindValue(4, $clean['audit_date']);
        $stmt->bindValue(5, $clean['road_count'], PDO::PARAM_INT);
        $stmt->bindValue(6, $clean['segment_count'], PDO::PARAM_INT);
        $stmt->bindValue(7, $clean['length_km']);
        $stmt->bindValue(8, $clean['score'], $clean['score'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(9, $clean['condition_label'], $clean['condition_label'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(10, $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(11, $data, $data === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(12, $adminId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Every past audit that has not been deleted (the PDF itself is not loaded). real_city_id is the
     * app's city of the same name, when there is one. Newest audit first.
     *
     * @return list<array<string,mixed>>
     */
    public function published(): array
    {
        return $this->pdo->query(
            "SELECT p.id, p.city_name, p.state, p.name, p.audit_date, p.road_count, p.segment_count, p.length_km,
                    p.score, p.condition_label,
                    CASE WHEN p.report_name IS NULL THEN 0 ELSE 1 END AS has_report,
                    (SELECT MIN(c.id) FROM cities c WHERE LOWER(c.name) = LOWER(p.city_name)) AS real_city_id
               FROM past_audits p
              WHERE p.deleted_at IS NULL
              ORDER BY p.audit_date DESC, p.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT id, city_name, name, audit_date, deleted_at,
                    CASE WHEN report_name IS NULL THEN 0 ELSE 1 END AS has_report
               FROM past_audits WHERE id = ?'
        );
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** The PDF of a past audit that has not been deleted: [file name, bytes], or null. */
    public function report(int $id): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT report_name, report_data FROM past_audits
              WHERE id = ? AND deleted_at IS NULL AND report_name IS NOT NULL'
        );
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $bytes = $row['report_data'];
        if (is_resource($bytes)) {
            $bytes = stream_get_contents($bytes);
        }
        return ($bytes === null || $bytes === false || $bytes === '') ? null : [(string)$row['report_name'], (string)$bytes];
    }

    /** Remove it from the public page (the row stays in the database). */
    public function delete(int $id): bool
    {
        $upd = $this->pdo->prepare('UPDATE past_audits SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL');
        $upd->execute([$id]);
        return $upd->rowCount() > 0;
    }
}
