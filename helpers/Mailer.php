<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Mailer.php
//  Sends one email through a provider's HTTP API (no SMTP needed).
//
//  Settings (Railway variables on the Parisar service):
//    MAIL_PROVIDER    brevo | resend
//    MAIL_API_KEY     the provider's API key
//    MAIL_FROM        sender address, verified with the provider
//    MAIL_FROM_NAME   optional, defaults to CycleAudit
//
//  When any of the first three is missing, mailerConfigured() is false and
//  callers skip sending (and log it) instead of failing.
// ═══════════════════════════════════════════════════════════════

/** @return array{provider:string, key:string, from:string, from_name:string} */
function mailerConfig(): array
{
    $get = static function (string $k, string $default = ''): string {
        $v = $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k);
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    };
    return [
        'provider'  => strtolower(trim($get('MAIL_PROVIDER'))),
        'key'       => trim($get('MAIL_API_KEY')),
        'from'      => trim($get('MAIL_FROM')),
        'from_name' => trim($get('MAIL_FROM_NAME', 'CycleAudit')),
    ];
}

/** @param array{provider:string, key:string, from:string, from_name:string}|null $cfg */
function mailerConfigured(?array $cfg = null): bool
{
    $cfg ??= mailerConfig();
    return in_array($cfg['provider'], ['brevo', 'resend'], true)
        && $cfg['key'] !== ''
        && filter_var($cfg['from'], FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * The HTTP request for one message, or null when the settings are incomplete.
 * Kept separate from the network call so it can be tested.
 *
 * @param  array{provider:string, key:string, from:string, from_name:string} $cfg
 * @return array{url:string, headers:list<string>, body:string}|null
 */
function mailerBuildRequest(array $cfg, string $toEmail, string $toName, string $subject, string $html, string $text): ?array
{
    if (!mailerConfigured($cfg)) {
        return null;
    }

    if ($cfg['provider'] === 'brevo') {
        $payload = [
            'sender'      => ['name' => $cfg['from_name'], 'email' => $cfg['from']],
            'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ];
        return [
            'url'     => 'https://api.brevo.com/v3/smtp/email',
            'headers' => ['api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'],
            'body'    => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    // resend
    $fromHeader = $cfg['from_name'] !== '' ? $cfg['from_name'] . ' <' . $cfg['from'] . '>' : $cfg['from'];
    $payload = [
        'from'    => $fromHeader,
        'to'      => [$toEmail],
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];
    return [
        'url'     => 'https://api.resend.com/emails',
        'headers' => ['Authorization: Bearer ' . $cfg['key'], 'Content-Type: application/json'],
        'body'    => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

/** Sends one email. True when the provider accepted it. Never throws, never logs the message body or the key. */
function mailerSend(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $req = mailerBuildRequest(mailerConfig(), $toEmail, $toName, $subject, $html, $text);
    if ($req === null) {
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('Mailer: the PHP cURL extension is not available.');
        return false;
    }

    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $req['body'],
        CURLOPT_HTTPHEADER     => $req['headers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Mailer: send failed (HTTP ' . $status . ($err !== '' ? ', ' . $err : '') . ')'
            . ($response !== false ? ' ' . substr((string)$response, 0, 300) : ''));
        return false;
    }
    return true;
}
