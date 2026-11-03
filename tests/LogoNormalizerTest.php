<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/LogoNormalizer.php';
require_once __DIR__ . '/../repositories/CollaboratorRepository.php';

/** Collaborator logos: any shape, size or background ends up as the same kind of picture. */
class LogoNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is not installed.');
        }
    }

    /** A logo of $w x $h: a dark block in the middle, on white, on nothing, or filling the picture. */
    private function logo(int $w, int $h, string $bg = 'white', int $margin = 0): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, $bg === 'clear' ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 255, 255, 255));
        $ink = imagecolorallocate($im, 20, 60, 160);
        imagefilledrectangle($im, $margin, $margin, $w - 1 - $margin, $h - 1 - $margin, $ink);
        if ($bg === 'badge') {                       // solid colour edge to edge, a white mark inside
            imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 20));
            imagefilledrectangle($im, intdiv($w, 3), intdiv($h, 3), intdiv($w * 2, 3), intdiv($h * 2, 3), imagecolorallocate($im, 255, 255, 255));
        }
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /** @return array{0:int,1:int,2:int,3:int} box of the visible (not see-through) pixels of a stored logo */
    private function visibleBox(string $bytes): array
    {
        $im = imagecreatefromstring($bytes);
        [$w, $h] = [imagesx($im), imagesy($im)];
        $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ((imagecolorat($im, $x, $y) >> 24 & 0x7F) < 64) {
                    $x0 = min($x0, $x); $x1 = max($x1, $x); $y0 = min($y0, $y); $y1 = max($y1, $y);
                }
            }
        }
        return [$x0, $y0, $x1, $y1];
    }

    public function testEveryShapeBecomesTheSameCanvas(): void
    {
        foreach ([[200, 200], [900, 120], [80, 400], [1500, 1500], [40, 40]] as [$w, $h]) {
            [$mime, $bytes] = collaboratorLogoNormalize($this->logo($w, $h), 'image/png');
            $this->assertContains($mime, ['image/webp', 'image/png'], "$w x $h");
            $info = getimagesizefromstring($bytes);
            $this->assertSame([COLLAB_CANVAS_W, COLLAB_CANVAS_H], [$info[0], $info[1]], "$w x $h");
            $this->assertTrue(collaboratorLogoIsNormalized($bytes));
        }
    }

    public function testLogoIsCentredAndItsCornersStayInsideTheCircle(): void
    {
        foreach ([[200, 200], [900, 120], [80, 400], [1000, 100]] as [$w, $h]) {
            [, $bytes] = collaboratorLogoNormalize($this->logo($w, $h), 'image/png');
            [$x0, $y0, $x1, $y1] = $this->visibleBox($bytes);
            $halfDiagonal = sqrt(($x1 - $x0 + 1) ** 2 + ($y1 - $y0 + 1) ** 2) / 2;
            $this->assertLessThanOrEqual(COLLAB_LOGO_CIRCLE / 2 + 3, $halfDiagonal, "$w x $h fits the inner circle");
            $this->assertEqualsWithDelta((COLLAB_CANVAS_W - 1) / 2, ($x0 + $x1) / 2, 2.0, "$w x $h centred across");
            $this->assertEqualsWithDelta((COLLAB_CANVAS_H - 1) / 2, ($y0 + $y1) / 2, 2.0, "$w x $h centred down");
        }
    }

    public function testBlankMarginsAreTrimmed(): void
    {
        // The same mark with and without a big white border comes out the same size.
        [, $tight]  = collaboratorLogoNormalize($this->logo(300, 100), 'image/png');
        [, $padded] = collaboratorLogoNormalize($this->logo(600, 400, 'white', 150), 'image/png');   // 300 x 100 mark, inside white
        $a = $this->visibleBox($tight);
        $b = $this->visibleBox($padded);
        $this->assertEqualsWithDelta($a[2] - $a[0], $b[2] - $b[0], 3.0);
        $this->assertEqualsWithDelta($a[3] - $a[1], $b[3] - $b[1], 3.0);
    }

    public function testTransparentAndWhiteBackgroundsGiveTheSameResult(): void
    {
        [, $white] = collaboratorLogoNormalize($this->logo(300, 100, 'white', 30), 'image/png');
        [, $clear] = collaboratorLogoNormalize($this->logo(300, 100, 'clear', 30), 'image/png');
        $a = $this->visibleBox($white);
        $b = $this->visibleBox($clear);
        foreach ([0, 1, 2, 3] as $i) {
            $this->assertEqualsWithDelta($a[$i], $b[$i], 3.0, "edge $i");
        }
    }

    public function testWideAndSquareLogosCarryTheSameVisualWeight(): void
    {
        $area = function (string $bytes): int {
            [$x0, $y0, $x1, $y1] = $this->visibleBox($bytes);
            return ($x1 - $x0 + 1) * ($y1 - $y0 + 1);
        };
        [, $square] = collaboratorLogoNormalize($this->logo(400, 400), 'image/png');
        [, $wide]   = collaboratorLogoNormalize($this->logo(800, 200), 'image/png');
        $ratio = $area($wide) / $area($square);
        // A very wide logo is kept inside the circle, so it carries a little less area than a square one
        // (but is wider), and never more.
        $this->assertGreaterThan(0.45, $ratio);
        $this->assertLessThan(1.2, $ratio);
    }

    public function testBadgeOnASolidColourKeepsItsBackground(): void
    {
        [, $bytes] = collaboratorLogoNormalize($this->logo(400, 200, 'badge'), 'image/png');
        $im = imagecreatefromstring($bytes);
        // The red badge was not trimmed away: its colour is still in the middle-left of the logo.
        $box = $this->visibleBox($bytes);
        $p = imagecolorat($im, $box[0] + 4, intdiv($box[1] + $box[3], 2));
        $this->assertGreaterThan(150, ($p >> 16) & 0xFF);
        $this->assertLessThan(100, ($p >> 8) & 0xFF);
    }

    public function testSomethingThatIsNotAPictureComesBackUntouched(): void
    {
        $this->assertSame(['image/png', 'not a picture'], collaboratorLogoNormalize('not a picture', 'image/png'));
        $this->assertSame(['image/png', ''], collaboratorLogoNormalize('', 'image/png'));
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 120, 60) . "\x08\x06\x00\x00\x00" . "\0\0\0\0";
        $this->assertSame(['image/png', $header], collaboratorLogoNormalize($header, 'image/png'));   // a header with no picture data
    }

    public function testALogoFromTheOldTwoToOneCanvasIsUpgraded(): void
    {
        $old = $this->logo(320, 160, 'clear', 30);         // the size every logo was stored at before the round tile
        $this->assertFalse(collaboratorLogoIsNormalized($old));
        [, $bytes] = collaboratorLogoNormalize($old, 'image/png');
        $this->assertTrue(collaboratorLogoIsNormalized($bytes));
        $info = getimagesizefromstring($bytes);
        $this->assertSame([320, 320], [$info[0], $info[1]]);
    }

    public function testAnAlreadyNormalisedLogoIsRecognised(): void
    {
        $this->assertFalse(collaboratorLogoIsNormalized($this->logo(200, 200)));
        [, $bytes] = collaboratorLogoNormalize($this->logo(200, 200), 'image/png');
        $this->assertTrue(collaboratorLogoIsNormalized($bytes));
        $this->assertFalse(collaboratorLogoIsNormalized('junk'));
    }

    public function testNormalisedLogoIsMuchSmallerThanALargeOriginal(): void
    {
        $orig = $this->logo(1500, 1500, 'white', 200);
        [, $bytes] = collaboratorLogoNormalize($orig, 'image/png');
        $this->assertLessThan(strlen($orig), strlen($bytes) + 1);   // never bigger than the picture it replaces
        $this->assertLessThan(60 * 1024, strlen($bytes));
    }

    public function testAnOldLogoIsUpgradedOnceAndItsAddressChanges(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE collaborators (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, role_label TEXT NULL,
            website_url TEXT NULL, logo_mime TEXT NULL, logo_data BLOB NULL, sort_order INTEGER NOT NULL DEFAULT 0,
            is_visible INTEGER NOT NULL DEFAULT 1, logo_scale INTEGER NOT NULL DEFAULT 100, logo_x INTEGER NOT NULL DEFAULT 0, logo_y INTEGER NOT NULL DEFAULT 0, created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT NULL)');
        $old = $this->logo(700, 300, 'white', 40);
        $stmt = $pdo->prepare("INSERT INTO collaborators (name, logo_mime, logo_data, created_by, updated_at) VALUES ('A', 'image/png', ?, 1, '2020-01-01 00:00:00')");
        $stmt->bindValue(1, $old, PDO::PARAM_LOB);
        $stmt->execute();

        $repo = new CollaboratorRepository($pdo);
        $up = $repo->upgradeLogo(1, ['image/png', $old]);
        $this->assertTrue(collaboratorLogoIsNormalized($up[1]));
        $row = $pdo->query('SELECT logo_mime, logo_data, updated_at FROM collaborators WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($up[0], $row['logo_mime']);
        $this->assertSame($up[1], (string)$row['logo_data']);          // kept, so it is only converted once
        $this->assertTrue($row['updated_at'] !== '2020-01-01 00:00:00'); // new address (&v=) for the browsers
        $this->assertSame($up, $repo->upgradeLogo(1, $up));              // a second time changes nothing
    }
}
