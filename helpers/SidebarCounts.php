<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/SidebarCounts.php
//  The little numbers beside sidebar links (Admin and City Leader), so what needs
//  you and what is already finished is visible without opening a page.
//  Every query is guarded: a failure only hides a number, it never breaks a page.
// ═══════════════════════════════════════════════════════════════

/**
 * Admin: audits waiting for the Admin's decision, and everything published
 * (live published audits plus past audits that visitors can see).
 *
 * @return array{awaiting:int, published:int}
 */
function sidebarAdminCounts(PDO $pdo): array
{
    $out = ['awaiting' => 0, 'published' => 0];
    try {
        $r = $pdo->query(
            "SELECT SUM(CASE WHEN status = 'awaiting_approval' THEN 1 ELSE 0 END) AS awaiting,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END)          AS published
               FROM city_audits"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['awaiting']  = (int)($r['awaiting'] ?? 0);
        $out['published'] = (int)($r['published'] ?? 0);
    } catch (Throwable $e) {
        // number stays 0
    }
    try {
        $out['published'] += (int)$pdo->query('SELECT COUNT(*) FROM past_audits WHERE deleted_at IS NULL')->fetchColumn();
    } catch (Throwable $e) {
        // number stays as it was
    }
    return $out;
}

/**
 * City Leader: segments waiting for this leader's review (in audits still open),
 * and this leader's finished (published) audits.
 *
 * @return array{review:int, completed:int}
 */
function sidebarLeaderCounts(PDO $pdo, int $leaderId): array
{
    $out = ['review' => 0, 'completed' => 0];
    try {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM segment_assignments sa
               JOIN city_audits a ON a.id = sa.audit_id
              WHERE a.leader_user_id = ? AND sa.status = 'submitted'
                AND a.status NOT IN ('voided', 'published')"
        );
        $q->execute([$leaderId]);
        $out['review'] = (int)$q->fetchColumn();
    } catch (Throwable $e) {
        // number stays 0
    }
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM city_audits WHERE leader_user_id = ? AND status = 'published'");
        $q->execute([$leaderId]);
        $out['completed'] = (int)$q->fetchColumn();
    } catch (Throwable $e) {
        // number stays 0
    }
    return $out;
}
