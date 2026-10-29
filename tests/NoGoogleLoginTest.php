<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sign-in is by email and password only. Google login was removed so that
 * every account is one Parisar controls. This keeps its pieces from coming back.
 */
final class NoGoogleLoginTest extends TestCase
{
    public function testGoogleLoginFilesAreGone(): void
    {
        $root = dirname(__DIR__);
        $this->assertFileDoesNotExist($root . '/auth/google_callback.php');
        $this->assertFileDoesNotExist($root . '/config/google_config.php');
    }

    public function testAuthCodeAndGuardsDoNotMentionGoogleSignIn(): void
    {
        $root  = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/auth/*.php') ?: [],
            glob($root . '/config/*.php') ?: [],
            glob($root . '/api/user/*.php') ?: [],
            glob($root . '/js/*.js') ?: []
        );
        $this->assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            $src = (string)file_get_contents($file);
            if (preg_match('/GOOGLE_(CLIENT|REDIRECT)|google_callback|googleusercontent|accounts\.google\.com|oauth2\.googleapis|Sign in with Google|Continue with Google/i', $src) === 1) {
                $offenders[] = basename($file);
            }
        }
        $this->assertSame([], $offenders, 'Google sign-in code must stay removed.');
    }

    public function testLoginAndRegisterPagesHaveNoGoogleButton(): void
    {
        $root = dirname(__DIR__);
        foreach (['/auth/login.php', '/auth/register.php'] as $page) {
            $src = (string)file_get_contents($root . $page);
            $this->assertStringNotContainsString('btn-google', $src, $page);
            $this->assertStringNotContainsString('f--google', $src, $page);
        }
    }
}
