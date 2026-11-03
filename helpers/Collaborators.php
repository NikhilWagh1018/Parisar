<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Collaborators.php
//  Pure rules for "Collaborations": the organisations working with Parisar
//  that the Admin lists on the public home page (no DB, no session), so they
//  are unit-tested. The Admin gives a name and, optionally, a role label, a
//  website and a logo picture.
// ═══════════════════════════════════════════════════════════════

const COLLAB_MAX_LOGO_BYTES = 1024 * 1024;   // 1 MB
const COLLAB_MAX_LOGO_SIDE  = 4000;          // pixels
const COLLAB_MIN_LOGO_SIDE  = 24;            // pixels

/** Picture types a logo may have (SVG is left out on purpose: it can carry scripts). */
const COLLAB_LOGO_TYPES = [
    IMAGETYPE_PNG  => 'image/png',
    IMAGETYPE_JPEG => 'image/jpeg',
    IMAGETYPE_GIF  => 'image/gif',
    IMAGETYPE_WEBP => 'image/webp',
];

/**
 * Check what the Admin typed.
 *
 * @param array<string,mixed>      $in   posted fields (name, role_label, website_url, remove_logo)
 * @param array<string,mixed>|null $file the logo's $_FILES entry (null or no file chosen = no new logo)
 * @return array{errors:array<string,string>,clean:array<string,mixed>}
 */
function collaboratorValidate(array $in, ?array $file): array
{
    $e = [];

    $name = collaboratorText($in['name'] ?? '');
    if ($name === '') {
        $e['name'] = 'Enter the organisation\'s name.';
    } elseif (mb_strlen($name) > 120) {
        $e['name'] = 'The name can be at most 120 characters.';
    }

    $role = collaboratorText($in['role_label'] ?? '');
    if (mb_strlen($role) > 60) {
        $e['role_label'] = 'The role can be at most 60 characters.';
    }

    $url = null;
    $urlRaw = trim((string)($in['website_url'] ?? ''));
    if ($urlRaw !== '') {
        $url = collaboratorUrlClean($urlRaw);
        if ($url === null) {
            $e['website_url'] = 'Enter a web address like https://example.org.';
        }
    }

    $logo = null;
    $hasFile = $file !== null && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        $res = collaboratorLogoCheck($file);
        if (is_string($res)) {
            $e['logo'] = $res;
        } else {
            $logo = $res;
        }
    }

    $remove = in_array((string)($in['remove_logo'] ?? ''), ['1', 'true', 'on'], true);

    return ['errors' => $e, 'clean' => [
        'name' => $name, 'role_label' => $role === '' ? null : $role, 'website_url' => $url,
        'logo' => $logo, 'remove_logo' => $remove && $logo === null,
    ]];
}

/** Single-line text: tabs and line breaks become spaces, runs of spaces collapse. */
function collaboratorText(mixed $v): string
{
    $s = preg_replace('/\s+/u', ' ', (string)$v) ?? '';
    return trim($s);
}

/**
 * A safe website address: http or https only. A bare "example.org" gets https://.
 * Returns null when it is not a usable address.
 */
function collaboratorUrlClean(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || mb_strlen($raw) > 255 || preg_match('/[\s<>"\'\\\\]/', $raw)) {
        return null;
    }
    if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $raw)) {
        $raw = 'https://' . $raw;
    }
    $parts = parse_url($raw);
    if ($parts === false || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return null;
    }
    $host = (string)($parts['host'] ?? '');
    if ($host === '' || !str_contains($host, '.') || !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
        return null;
    }
    if (mb_strlen($raw) > 255 || filter_var($raw, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    return $raw;
}

/**
 * Why an uploaded file cannot be used as a logo (a message), or its details.
 *
 * @param array<string,mixed> $file
 * @return string|array{tmp:string,mime:string}
 */
function collaboratorLogoCheck(array $file): string|array
{
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return 'The logo is too large. The limit is 1 MB.';
    }
    if ($code !== UPLOAD_ERR_OK) {
        return 'The logo could not be uploaded. Please try again.';
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return 'The logo file is empty.';
    }
    if ($size > COLLAB_MAX_LOGO_BYTES) {
        return 'The logo is too large. The limit is 1 MB.';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $info = ($tmp !== '' && is_readable($tmp)) ? @getimagesize($tmp) : false;
    if ($info === false || !isset(COLLAB_LOGO_TYPES[(int)$info[2]])) {
        return 'The logo must be a PNG, JPG, WebP or GIF picture.';
    }
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < COLLAB_MIN_LOGO_SIDE || $h < COLLAB_MIN_LOGO_SIDE) {
        return 'The logo is too small. Use a picture at least ' . COLLAB_MIN_LOGO_SIDE . ' pixels wide and tall.';
    }
    if ($w > COLLAB_MAX_LOGO_SIDE || $h > COLLAB_MAX_LOGO_SIDE) {
        return 'The logo is too big. Use a picture up to ' . COLLAB_MAX_LOGO_SIDE . ' pixels wide and tall.';
    }
    return ['tmp' => $tmp, 'mime' => COLLAB_LOGO_TYPES[(int)$info[2]]];
}

/**
 * One collaborator in the shape the home page reads.
 *
 * @param array<string,mixed> $row row from CollaboratorRepository::visible()
 * @return array<string,mixed>
 */
function collaboratorShape(array $row): array
{
    $id = (int)$row['id'];
    $v  = (int)strtotime((string)($row['updated_at'] ?? '')) ?: 1;
    return [
        'id'       => $id,
        'name'     => (string)$row['name'],
        'role'     => ($row['role_label'] ?? null) === null || $row['role_label'] === '' ? null : (string)$row['role_label'],
        'url'      => ($row['website_url'] ?? null) === null || $row['website_url'] === '' ? null : (string)$row['website_url'],
        'logo_url' => ((int)($row['has_logo'] ?? 0)) === 1 ? '/api/public/collaborator_logo.php?id=' . $id . '&v=' . $v : null,
    ];
}

/** Send a logo picture to the browser (shared by the public and the Admin endpoints). */
function collaboratorSendLogo(string $mime, string $bytes, bool $public): never
{
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    // The public logo address carries a version stamp (&v=...) that changes whenever the logo does,
    // so a versioned logo can be kept for a year. Without the stamp it is kept for a day.
    $cache = !$public ? 'private, no-cache'
        : (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=86400');
    header('Cache-Control: ' . $cache);
    echo $bytes;
    exit;
}
