<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ad blockers hide any element whose class name starts with "ad-" (ad-btn,
 * ad-table, ad-scroll ...). The Admin dashboard once lost its Review buttons
 * and both tables that way, while looking fine on a machine without a blocker.
 * Keep such names out of our own pages and stylesheets.
 */
final class NoAdBlockerClassNamesTest extends TestCase
{
    public function testOwnPagesAndStylesheetsAvoidTheAdPrefix(): void
    {
        $root  = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/pages/*.php') ?: [],
            glob($root . '/pages/partials/*.php') ?: [],
            glob($root . '/css/*.css') ?: []
        );
        $this->assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            $src = (string)file_get_contents($file);
            if (preg_match('/(?<![A-Za-z0-9_])ad-[a-z]/', $src, $m, PREG_OFFSET_CAPTURE) === 1) {
                $offenders[] = basename($file) . ' near byte ' . $m[0][1];
            }
        }
        $this->assertSame([], $offenders, 'Class names must not start with "ad-": ad blockers hide them.');
    }
}
