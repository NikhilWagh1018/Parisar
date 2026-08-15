<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/user/audit_export.php
//  GET — exports the logged-in user's personal audit history as a
//        real .xlsx file (via PhpSpreadsheet), respecting whatever
//        status/range/sort filters are currently selected on the
//        "My Audits" page.
//
//  Query params (same meaning as audit_history_list.php, no `page`
//  — the export always includes every row that matches the filters):
//    status = all | active | completed        (default: all)
//    range  = all | week | month               (default: all)
//    sort   = recent | name | score            (default: recent)
//
//  Filtering/sorting/scoring logic is shared with
//  audit_history_list.php via helpers/AuditHistoryFilter.php — the
//  export can never show a different set of rows than what the user
//  sees on screen with the same filters selected.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';
require_once __DIR__ . '/../../services/ScoreService.php';
require_once __DIR__ . '/../../helpers/AuditHistoryFilter.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $status = $_GET['status'] ?? 'all';
    $range  = $_GET['range']  ?? 'all';
    $sort   = $_GET['sort']   ?? 'recent';

    $repo = new SegmentRepository($pdo);
    $rows = $repo->personalAuditList($CURRENT_USER_ID);
    $rows = filterAndSortAuditRows($rows, $status, $range, $sort, $pdo);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('My Audits');

    $headers = ['Road Name', 'Segment', 'Date', 'Status', 'Condition', 'Score', 'Length (m)'];
    $sheet->fromArray($headers, null, 'A1');

    $headerStyle = $sheet->getStyle('A1:G1');
    $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $headerStyle->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('3D7A1F');
    $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $rowNum = 2;
    foreach ($rows as $r) {
        $sheet->setCellValue('A' . $rowNum, $r['road_name']);
        $sheet->setCellValue('B' . $rowNum, $r['segment_number']);
        $sheet->setCellValue('C' . $rowNum, !empty($r['created_at'])
            ? (new DateTime($r['created_at']))->format('d M Y')
            : '—');
        $sheet->setCellValue('D' . $rowNum, $r['session_status'] === 'active' ? 'Active' : 'Completed');
        $sheet->setCellValue('E' . $rowNum, $r['condition'] ?? '—');
        $sheet->setCellValue('F' . $rowNum, $r['score'] ?? '—');
        $sheet->setCellValue('G' . $rowNum, $r['segment_length'] ?? '—');
        $rowNum++;
    }

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Filename reflects the active filters so repeated exports with
    // different filter combos don't silently overwrite each other in
    // the user's Downloads folder.
    $parts = ['my_audits'];
    if ($status !== 'all') $parts[] = $status;
    if ($range  !== 'all') $parts[] = $range;
    $parts[] = date('Y-m-d');
    $filename = implode('_', $parts) . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    error_log('api/user/audit_export.php error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not generate export.']);
}
