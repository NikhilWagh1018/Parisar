<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/Mailer.php';

/** The email request is built from settings alone; nothing here touches the network. */
class MailerTest extends TestCase
{
    private function cfg(string $provider, string $key = 'k-123', string $from = 'no-reply@parisar.org', string $name = 'CycleAudit'): array
    {
        return ['provider' => $provider, 'key' => $key, 'from' => $from, 'from_name' => $name];
    }

    public function test_configured_needs_provider_key_and_valid_sender(): void
    {
        $this->assertTrue(mailerConfigured($this->cfg('brevo')));
        $this->assertTrue(mailerConfigured($this->cfg('resend')));
        $this->assertFalse(mailerConfigured($this->cfg('')));
        $this->assertFalse(mailerConfigured($this->cfg('smtp')));
        $this->assertFalse(mailerConfigured($this->cfg('brevo', '')));
        $this->assertFalse(mailerConfigured($this->cfg('brevo', 'k', 'not-an-email')));
        $this->assertFalse(mailerConfigured($this->cfg('brevo', 'k', '')));
    }

    public function test_incomplete_settings_build_no_request(): void
    {
        $this->assertNull(mailerBuildRequest($this->cfg(''), 'a@b.org', 'A', 's', '<p>h</p>', 't'));
    }

    public function test_brevo_request(): void
    {
        $r = mailerBuildRequest($this->cfg('brevo'), 'asha@example.org', 'Asha', 'Subject', '<p>Hello</p>', 'Hello');
        $this->assertSame('https://api.brevo.com/v3/smtp/email', $r['url']);
        $this->assertContains('api-key: k-123', $r['headers']);
        $body = json_decode($r['body'], true);
        $this->assertSame('no-reply@parisar.org', $body['sender']['email']);
        $this->assertSame('CycleAudit', $body['sender']['name']);
        $this->assertSame('asha@example.org', $body['to'][0]['email']);
        $this->assertSame('Subject', $body['subject']);
        $this->assertSame('<p>Hello</p>', $body['htmlContent']);
        $this->assertSame('Hello', $body['textContent']);
    }

    public function test_resend_request(): void
    {
        $r = mailerBuildRequest($this->cfg('resend'), 'asha@example.org', 'Asha', 'Subject', '<p>Hello</p>', 'Hello');
        $this->assertSame('https://api.resend.com/emails', $r['url']);
        $this->assertContains('Authorization: Bearer k-123', $r['headers']);
        $body = json_decode($r['body'], true);
        $this->assertSame('CycleAudit <no-reply@parisar.org>', $body['from']);
        $this->assertSame(['asha@example.org'], $body['to']);
        $this->assertSame('<p>Hello</p>', $body['html']);
        $this->assertSame('Hello', $body['text']);
    }

    public function test_the_key_never_goes_into_the_body(): void
    {
        foreach (['brevo', 'resend'] as $p) {
            $r = mailerBuildRequest($this->cfg($p, 'secret-key-xyz'), 'a@b.org', 'A', 's', 'h', 't');
            $this->assertStringNotContainsString('secret-key-xyz', $r['body'], $p);
        }
    }
}
