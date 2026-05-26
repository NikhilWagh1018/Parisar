<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/error_handler.php
//  Global error + exception + shutdown handler for Parisar.
//  Wire in via: require_once __DIR__ . '/error_handler.php';
//               registerErrorHandlers();
// ═══════════════════════════════════════════════════════════════

function registerErrorHandlers(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    // ── PHP errors → appLog ───────────────────────────────────
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false; // respect @ operator
        }
        appLog('error', $message, [
            'severity' => $severity,
            'file'     => $file,
            'line'     => $line,
        ]);
        return true;
    });

    // ── Uncaught exceptions ───────────────────────────────────
    set_exception_handler(function (Throwable $e): void {
        appLog('exception', $e->getMessage(), [
            'class' => get_class($e),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        http_response_code(500);

        if (php_sapi_name() === 'cli') {
            exit(1);
        }

        $isApi = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
              || str_contains($_SERVER['REQUEST_URI']  ?? '', '/api/')
              || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

        if ($isApi) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        } else {
            echo '<!DOCTYPE html><html><body><h2>Something went wrong.</h2>'
               . '<p>Our team has been notified. Please try again shortly.</p></body></html>';
        }
        exit(1);
    });

    // ── Fatal shutdown errors ─────────────────────────────────
    // IMPORTANT: PHP fatal errors (E_ERROR, E_PARSE, etc.) do NOT trigger
    // set_error_handler or set_exception_handler — only register_shutdown_function.
    // Without a JSON response here, Apache returns 500 with empty body (Content-Length: 0).
    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            appLog('fatal', $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
            ]);

            if (php_sapi_name() === 'cli') {
                return;
            }

            // Send a JSON error response so the client gets something meaningful
            // instead of an empty 500 body.
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'error'   => 'Fatal server error: ' . $error['message'],
                'file'    => basename($error['file']),
                'line'    => $error['line'],
            ]);
        }
    });
}

/**
 * Append a structured JSON log line to logs/app.log.
 * Safe to call before the DB is available.
 *
 * @param string               $level   error|exception|fatal|info|warning
 * @param string               $message Human-readable description
 * @param array<string,mixed>  $context Extra key-value data
 */
function appLog(string $level, string $message, array $context = []): void
{
    $logDir = dirname(__DIR__) . '/logs';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $entry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'level'     => strtoupper($level),
        'message'   => $message,
        'context'   => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    file_put_contents($logDir . '/app.log', $entry, FILE_APPEND | LOCK_EX);
}
