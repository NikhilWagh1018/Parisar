<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/CollaboratorRepository.php
//  The organisations listed under "Collaborations" on the home page
//  (see helpers/Collaborators.php). "Delete" only sets deleted_at: the row
//  and its logo stay in the database.
//  Plain SQL that runs on MySQL and on SQLite (the tests use SQLite).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../helpers/LogoNormalizer.php';

class CollaboratorRepository
{
    private const COLS = "id, name, role_label, website_url, sort_order, is_visible, updated_at,
                          CASE WHEN logo_mime IS NULL THEN 0 ELSE 1 END AS has_logo";

    public function __construct(private PDO $pdo) {}

    /** @param array<string,mixed> $clean from collaboratorValidate() */
    public function create(array $clean, int $adminId): int
    {
        $mime = null;
        $data = null;
        if ($clean['logo'] !== null) {
            // Every logo is stored the same way: trimmed, evenly sized, centred (helpers/LogoNormalizer.php).
            [$mime, $data] = collaboratorLogoNormalize(
                (string)file_get_contents((string)$clean['logo']['tmp']),
                (string)$clean['logo']['mime']
            );
        }
        $next = (int)$this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM collaborators WHERE deleted_at IS NULL')->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO collaborators (name, role_label, website_url, logo_mime, logo_data, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $clean['name']);
        $stmt->bindValue(2, $clean['role_label'], $clean['role_label'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(3, $clean['website_url'], $clean['website_url'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(4, $mime, $mime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(5, $data, $data === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(6, $next, PDO::PARAM_INT);
        $stmt->bindValue(7, $adminId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Change the details. A new logo replaces the old one; remove_logo clears it; otherwise the
     * logo is left alone.
     *
     * @param array<string,mixed> $clean from collaboratorValidate()
     */
    public function update(int $id, array $clean): bool
    {
        $sql = 'UPDATE collaborators SET name = ?, role_label = ?, website_url = ?, updated_at = CURRENT_TIMESTAMP';
        $logo = $clean['logo'];
        if ($logo !== null) {
            $sql .= ', logo_mime = ?, logo_data = ?';
        } elseif (!empty($clean['remove_logo'])) {
            $sql .= ', logo_mime = NULL, logo_data = NULL';
        }
        $stmt = $this->pdo->prepare($sql . ' WHERE id = ? AND deleted_at IS NULL');
        $i = 1;
        $stmt->bindValue($i++, $clean['name']);
        $stmt->bindValue($i++, $clean['role_label'], $clean['role_label'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue($i++, $clean['website_url'], $clean['website_url'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        if ($logo !== null) {
            [$nMime, $nData] = collaboratorLogoNormalize(
                (string)file_get_contents((string)$logo['tmp']),
                (string)$logo['mime']
            );
            $stmt->bindValue($i++, $nMime);
            $stmt->bindValue($i++, $nData, PDO::PARAM_LOB);
        }
        $stmt->bindValue($i, $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * What the home page shows: visible, not deleted, in the Admin's order.
     *
     * @return list<array<string,mixed>>
     */
    public function visible(): array
    {
        return $this->pdo->query(
            'SELECT ' . self::COLS . ' FROM collaborators
              WHERE deleted_at IS NULL AND is_visible = 1
              ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Everyone the Admin can manage (hidden ones too), in the Admin's order.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT ' . self::COLS . ' FROM collaborators WHERE deleted_at IS NULL ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT ' . self::COLS . ', deleted_at FROM collaborators WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** The logo: [mime, bytes], or null. $onlyVisible = what the public may see. */
    public function logo(int $id, bool $onlyVisible): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT logo_mime, logo_data FROM collaborators
              WHERE id = ? AND deleted_at IS NULL AND logo_mime IS NOT NULL' . ($onlyVisible ? ' AND is_visible = 1' : '')
        );
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $bytes = $row['logo_data'];
        if (is_resource($bytes)) {
            $bytes = stream_get_contents($bytes);
        }
        return ($bytes === null || $bytes === false || $bytes === '') ? null : [(string)$row['logo_mime'], (string)$bytes];
    }

    /**
     * A logo saved before logos were normalised is converted the first time it is asked for, and the
     * converted picture is kept. updated_at moves on, which changes the logo's address (&v=), so no
     * browser holds on to the old picture. Returns the logo to send: [mime, bytes].
     *
     * @param array{0:string,1:string} $logo [mime, bytes] as stored
     * @return array{0:string,1:string}
     */
    public function upgradeLogo(int $id, array $logo): array
    {
        if (collaboratorLogoIsNormalized($logo[1])) {
            return $logo;
        }
        [$mime, $bytes] = collaboratorLogoNormalize($logo[1], $logo[0]);
        if ($bytes === $logo[1]) {
            return $logo;                    // could not be processed: leave it as it is
        }
        $stmt = $this->pdo->prepare(
            'UPDATE collaborators SET logo_mime = ?, logo_data = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->bindValue(1, $mime);
        $stmt->bindValue(2, $bytes, PDO::PARAM_LOB);
        $stmt->bindValue(3, $id, PDO::PARAM_INT);
        $stmt->execute();
        return [$mime, $bytes];
    }

    public function setVisible(int $id, bool $visible): bool
    {
        $u = $this->pdo->prepare('UPDATE collaborators SET is_visible = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL');
        $u->execute([$visible ? 1 : 0, $id]);
        return $u->rowCount() > 0;
    }

    /** Remove it from the home page and the Admin list (the row stays in the database). */
    public function delete(int $id): bool
    {
        $u = $this->pdo->prepare('UPDATE collaborators SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL');
        $u->execute([$id]);
        return $u->rowCount() > 0;
    }

    /**
     * Move one step up or down. The whole list is renumbered 1..n, so the order stays clean
     * even if two had the same number. Returns false when it is already first/last.
     */
    public function move(int $id, string $direction): bool
    {
        $ids = array_map('intval', array_column($this->all(), 'id'));
        $pos = array_search($id, $ids, true);
        $to  = $direction === 'up' ? $pos - 1 : $pos + 1;
        if ($pos === false || $to < 0 || $to >= count($ids)) {
            return false;
        }
        [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
        $this->pdo->beginTransaction();
        try {
            $u = $this->pdo->prepare('UPDATE collaborators SET sort_order = ? WHERE id = ?');
            foreach ($ids as $i => $cid) {
                $u->execute([$i + 1, $cid]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return true;
    }
}
