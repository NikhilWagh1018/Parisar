<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/LogoNormalizer.php
//  Makes every collaborator logo the same kind of picture, whatever the Admin uploads:
//    - blank margins (white or transparent) are trimmed off,
//    - the logo is scaled by its AREA, so a wide wordmark and a square emblem carry the same
//      visual weight, and its corners are kept inside one common inner circle, so nothing is
//      ever cut off by the round tile it is shown in,
//    - it is centred on one fixed transparent square canvas (320 x 320),
//    - and saved as WebP (PNG when the server has no WebP), which is also far smaller.
//  A logo on a solid colour (a badge) is kept as it is: only its size is normalised.
//  Anything that cannot be decoded is returned untouched, so an upload never fails because of this.
//  Needs the GD extension, which the Dockerfile installs.
// ═══════════════════════════════════════════════════════════════

const COLLAB_CANVAS_W   = 320;     // pixels: the stored size of every logo (a square: it is shown in a circle)
const COLLAB_CANVAS_H   = 320;
const COLLAB_LOGO_AREA  = 22000;   // the pixel area every logo is aiming for (before the circle limit below)
const COLLAB_LOGO_CIRCLE = 236;    // the logo's corners stay inside a circle of this diameter, which
                                   // leaves a margin inside the round tile (the whole canvas is 320)
const COLLAB_WORK_SIDE  = 800;     // the picture is analysed at no more than this many pixels a side
const COLLAB_WHITE      = 235;     // a pixel with every channel at or above this counts as blank white
const COLLAB_ALPHA_GONE = 100;     // GD alpha (0 opaque .. 127 clear): at or above this = see-through

/** True when these bytes already are a normalised logo (so there is nothing left to do). */
function collaboratorLogoIsNormalized(string $bytes): bool
{
    $i = @getimagesizefromstring($bytes);
    return $i !== false && (int)$i[0] === COLLAB_CANVAS_W && (int)$i[1] === COLLAB_CANVAS_H;
}

/**
 * The logo as it should be stored.
 *
 * @return array{0:string,1:string} [mime, bytes]: the normalised logo, or the original when it cannot be processed
 */
function collaboratorLogoNormalize(string $bytes, string $mime): array
{
    $original = [$mime, $bytes];
    if (!function_exists('imagecreatefromstring') || $bytes === '') {
        return $original;
    }
    try {
        $out = collaboratorLogoRender($bytes);
        return $out ?? $original;
    } catch (Throwable $e) {
        error_log('collaboratorLogoNormalize: ' . $e->getMessage());
        return $original;
    }
}

/** @return array{0:string,1:string}|null */
function collaboratorLogoRender(string $bytes): ?array
{
    $info = @getimagesizefromstring($bytes);
    if ($info === false || (int)$info[0] < 1 || (int)$info[1] < 1) {
        return null;
    }
    [$sw, $sh] = [(int)$info[0], (int)$info[1]];

    // A big picture needs a lot of memory to open: make sure there is room, or leave it alone.
    $need = (int)($sw * $sh * 4 * 2.2) + 8 * 1024 * 1024;
    if (!collaboratorLogoHasMemory($need)) {
        return null;
    }

    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return null;
    }

    // 1. A working copy: true colour, alpha kept, at most COLLAB_WORK_SIDE on the long side.
    $k  = min(1.0, COLLAB_WORK_SIDE / max($sw, $sh));
    $ww = max(1, (int)round($sw * $k));
    $wh = max(1, (int)round($sh * $k));
    $work = imagecreatetruecolor($ww, $wh);
    imagealphablending($work, false);
    imagesavealpha($work, true);
    imagefill($work, 0, 0, imagecolorallocatealpha($work, 255, 255, 255, 127));
    imagecopyresampled($work, $src, 0, 0, 0, 0, $ww, $wh, $sw, $sh);
    imagedestroy($src);

    // 2. The part that is the logo itself.
    [$x0, $y0, $x1, $y1] = collaboratorLogoInkBox($work, $ww, $wh);
    $cw = $x1 - $x0 + 1;
    $ch = $y1 - $y0 + 1;

    // 3. Its size on the canvas: the same area for every logo, shrunk only as far as needed so that
    //    its diagonal (corner to corner) fits the inner circle. A square or slightly wide logo gets
    //    the full area; a very wide or very tall one gets a little less.
    $ratio = $cw / $ch;
    $tw = sqrt(COLLAB_LOGO_AREA * $ratio);
    $th = sqrt(COLLAB_LOGO_AREA / $ratio);
    $diag = sqrt($tw * $tw + $th * $th);
    $fit = min(1.0, COLLAB_LOGO_CIRCLE / $diag);
    $tw = max(1, (int)round($tw * $fit));
    $th = max(1, (int)round($th * $fit));

    // 4. Centre it on the fixed transparent square canvas.
    $canvas = imagecreatetruecolor(COLLAB_CANVAS_W, COLLAB_CANVAS_H);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
    imagecopyresampled(
        $canvas, $work,
        intdiv(COLLAB_CANVAS_W - $tw, 2), intdiv(COLLAB_CANVAS_H - $th, 2), $x0, $y0,
        $tw, $th, $cw, $ch
    );
    imagedestroy($work);

    // 5. Save: WebP when the server can, otherwise PNG.
    ob_start();
    if (function_exists('imagewebp')) {
        $ok = imagewebp($canvas, null, 88);
        $type = 'image/webp';
    } else {
        $ok = imagepng($canvas, null, 9);
        $type = 'image/png';
    }
    $data = (string)ob_get_clean();
    imagedestroy($canvas);

    return ($ok && $data !== '') ? [$type, $data] : null;
}

/**
 * The box [x0, y0, x1, y1] that holds the logo. When the picture sits on blank white or on
 * nothing (transparent), the blank margin is left out. On any other background the whole
 * picture is the logo.
 *
 * @return array{0:int,1:int,2:int,3:int}
 */
function collaboratorLogoInkBox(\GdImage $im, int $w, int $h): array
{
    $whole = [0, 0, $w - 1, $h - 1];
    $corners = [[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]];

    $allWhite = true;
    foreach ($corners as [$cx, $cy]) {
        $p = imagecolorat($im, $cx, $cy);
        $a = ($p >> 24) & 0x7F;
        $clear = $a >= COLLAB_ALPHA_GONE;
        $white = $clear || collaboratorLogoIsWhite($p);
        $allWhite = $allWhite && $white;
    }
    if (!$allWhite) {
        return $whole;                       // a coloured badge or full-bleed picture: keep it all
    }

    $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $p = imagecolorat($im, $x, $y);
            if ((($p >> 24) & 0x7F) >= COLLAB_ALPHA_GONE || collaboratorLogoIsWhite($p)) {
                continue;
            }
            if ($x < $x0) { $x0 = $x; }
            if ($x > $x1) { $x1 = $x; }
            if ($y < $y0) { $y0 = $y; }
            if ($y > $y1) { $y1 = $y; }
        }
    }
    return $x1 < 0 ? $whole : [$x0, $y0, $x1, $y1];   // nothing but blank: leave it as it is
}

function collaboratorLogoIsWhite(int $pixel): bool
{
    return (($pixel >> 16) & 0xFF) >= COLLAB_WHITE
        && (($pixel >> 8) & 0xFF) >= COLLAB_WHITE
        && ($pixel & 0xFF) >= COLLAB_WHITE;
}

/** Is there room in PHP's memory limit for $need more bytes? Asks for more once if it is short. */
function collaboratorLogoHasMemory(int $need): bool
{
    $limit = ini_get('memory_limit');
    if ($limit === false || $limit === '' || $limit === '-1') {
        return true;
    }
    $n = (int)$limit;
    $bytes = match (strtolower(substr($limit, -1))) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
    if ($bytes - memory_get_usage() >= $need) {
        return true;
    }
    @ini_set('memory_limit', '384M');
    return (int)ini_get('memory_limit') >= 384 || ini_get('memory_limit') === '-1';
}
