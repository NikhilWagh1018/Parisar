<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/PastAudits.php
//  Pure rules for "past audits": audits done before CycleAudit that the
//  Admin adds to the public Audit Data page (no DB, no session), so they are
//  unit-tested. The Admin gives the audit's summary figures and, optionally,
//  its PDF report.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/Cities.php';
require_once __DIR__ . '/../services/ScoreHelpers.php';

const PAST_AUDIT_MAX_PDF_BYTES = 8 * 1024 * 1024;

/**
 * Check what the Admin typed.
 *
 * @param array<string,mixed>      $in    posted fields
 * @param array<string,mixed>|null $file  the PDF's $_FILES entry (null or no file chosen = no report)
 * @param string                   $today YYYY-MM-DD (a past audit cannot be dated in the future)
 * @return array{errors:array<string,string>,clean:array<string,mixed>}
 */
function pastAuditValidate(array $in, ?array $file, string $today): array
{
    $e = [];

    // City: only cities from the standard list, so the spelling stays the same everywhere.
    $cityRaw = is_string($in['city_name'] ?? null) ? cityNameClean((string)$in['city_name']) : '';
    $city = null;
    $state = '';
    if ($cityRaw === '') {
        $e['city_name'] = 'Choose a city.';
    } else {
        [$city, $state] = cityResolve($cityRaw, (string)($in['state'] ?? ''));
        if ($city === null) {
            $e['city_name'] = 'Pick a city from the list.';
        } else {
            $state = cityStateFor($city) ?? (string)$state;
        }
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        $e['name'] = 'Give the audit a name.';
    } elseif (mb_strlen($name) > 150) {
        $e['name'] = 'The name can be at most 150 characters.';
    }

    $date = trim((string)($in['audit_date'] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if ($d === false || $d->format('Y-m-d') !== $date) {
        $e['audit_date'] = 'Enter the audit date.';
    } elseif ($date < '2000-01-01') {
        $e['audit_date'] = 'The date cannot be before 2000.';
    } elseif ($date > $today) {
        $e['audit_date'] = 'A past audit cannot be dated in the future.';
    }

    $roads = pastAuditWholeNumber($in['road_count'] ?? '', 1, 100000);
    if ($roads === null) {
        $e['road_count'] = 'Enter the number of roads (1 or more).';
    }
    $segments = pastAuditWholeNumber($in['segment_count'] ?? '', 1, 1000000);
    if ($segments === null) {
        $e['segment_count'] = 'Enter the number of segments (1 or more).';
    }

    $lenRaw = str_replace(',', '.', trim((string)($in['length_km'] ?? '')));
    $length = null;
    if ($lenRaw !== '' && preg_match('/^\d{1,6}(\.\d{1,2})?$/', $lenRaw)) {
        $length = round((float)$lenRaw, 1);
    }
    if ($length === null || $length <= 0 || $length > 100000) {
        $e['length_km'] = 'Enter the total length in km, for example 12.5.';
        $length = null;
    }

    // Score is optional. A score gives the condition badge (the same bands the live audits use).
    $scoreRaw = str_replace(',', '.', trim((string)($in['score'] ?? '')));
    $score = null;
    $condition = null;
    if ($scoreRaw !== '') {
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $scoreRaw) || (float)$scoreRaw > 100) {
            $e['score'] = 'The score is a number from 0 to 100, or leave it empty.';
        } else {
            $score = round((float)$scoreRaw, 2);
            $condition = ScoreHelpers::scoreToCondition($score);
        }
    }

    // PDF report: optional.
    $report = null;
    $hasFile = $file !== null && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        $err = pastAuditPdfError($file);
        if ($err !== null) {
            $e['report'] = $err;
        } else {
            $report = ['tmp' => (string)$file['tmp_name'], 'name' => pastAuditFileName((string)($file['name'] ?? ''))];
        }
    }

    return ['errors' => $e, 'clean' => [
        'city_name' => (string)$city, 'state' => $state, 'name' => $name, 'audit_date' => $date,
        'road_count' => $roads, 'segment_count' => $segments, 'length_km' => $length,
        'score' => $score, 'condition_label' => $condition, 'report' => $report,
    ]];
}

/** A whole number from $min to $max, or null. */
function pastAuditWholeNumber(mixed $v, int $min, int $max): ?int
{
    $s = trim((string)$v);
    if (!preg_match('/^\d{1,9}$/', $s)) {
        return null;
    }
    $n = (int)$s;
    return ($n >= $min && $n <= $max) ? $n : null;
}

/** Why an uploaded file cannot be used as the PDF report (null = fine). */
function pastAuditPdfError(array $file): ?string
{
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return 'The PDF is too large. The limit is 8 MB.';
    }
    if ($code !== UPLOAD_ERR_OK) {
        return 'The PDF could not be uploaded. Please try again.';
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return 'The PDF is empty.';
    }
    if ($size > PAST_AUDIT_MAX_PDF_BYTES) {
        return 'The PDF is too large. The limit is 8 MB.';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $head = ($tmp !== '' && is_readable($tmp)) ? (string)file_get_contents($tmp, false, null, 0, 5) : '';
    if ($head !== '%PDF-') {
        return 'The report must be a PDF file.';
    }
    return null;
}

/** A safe file name for the download (letters, digits, space, dot, dash, underscore). */
function pastAuditFileName(string $name): string
{
    $base = basename(str_replace('\\', '/', $name));
    $base = preg_replace('/[^A-Za-z0-9 ._\-]/', '', $base) ?? '';
    $base = trim($base, " .");
    if (!str_ends_with(strtolower($base), '.pdf')) {
        $base = ($base === '' ? 'audit-report' : $base) . '.pdf';
    }
    return mb_substr($base, 0, 200);
}

/**
 * A number the landing page can use to tell cities apart: the city's real id when it exists in the app,
 * otherwise a fixed number made from its name (so a city with only past audits still shows up).
 */
function pastAuditCityKey(string $cityName, ?int $realCityId): int
{
    if ($realCityId !== null && $realCityId > 0) {
        return $realCityId;
    }
    return 10000000000 + crc32(mb_strtolower(trim($cityName)));
}

/**
 * One past audit, in the shape the landing page reads (the live audits' fields plus `past` and `report_url`).
 *
 * @param array<string,mixed> $row row from PastAuditRepository::published()
 * @return array<string,mixed>
 */
function pastAuditShape(array $row): array
{
    $date = substr((string)$row['audit_date'], 0, 10);
    $id   = (int)$row['id'];
    return [
        'id'         => $id,
        'name'       => (string)$row['name'],
        'city_id'    => pastAuditCityKey((string)$row['city_name'], isset($row['real_city_id']) ? (int)$row['real_city_id'] : null),
        'city'       => (string)$row['city_name'],
        'state'      => (string)$row['state'],
        'date'       => $date,
        'year'       => (int)substr($date, 0, 4),
        'month'      => (int)substr($date, 5, 2),
        'roads'      => (int)$row['road_count'],
        'segments'   => (int)$row['segment_count'],
        'length_km'  => round((float)$row['length_km'], 1),
        'score'      => $row['score'] === null ? null : (float)$row['score'],
        'condition'  => ($row['condition_label'] ?? null) === null || $row['condition_label'] === '' ? null : (string)$row['condition_label'],
        'past'       => true,
        'report_url' => ((int)($row['has_report'] ?? 0)) === 1 ? '/api/public/past_audit_report.php?id=' . $id : null,
    ];
}
