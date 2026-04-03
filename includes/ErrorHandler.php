<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

/**
 * Gestionnaire d'erreurs global (web + API) avec logs structurés et Sentry optionnel.
 */
class ErrorHandler
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    /**
     * Réponse JSON uniforme pour erreurs internes API (log + Sentry + exit).
     *
     * @return never
     */
    public static function apiError(\Throwable $e, int $code = 500): void
    {
        self::logThrowable($e, 'CRITICAL');
        self::captureSentryIfConfigured($e);
        self::emitJsonApiError($code, $e);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $level = self::severityToLevelName($severity);
        self::logStructured($level, $message, $file, $line, '');

        $monologLevel = self::severityToMonologLevel($severity);
        AppLogger::getLogger()->log($monologLevel, $message, [
            'file' => $file,
            'line' => $line,
            'severity' => $severity,
        ]);

        if (in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            return false;
        }

        return true;
    }

    public static function handleException(\Throwable $exception): void
    {
        self::logThrowable($exception, 'CRITICAL');
        self::captureSentryIfConfigured($exception);

        if (self::isApiRequest()) {
            self::emitJsonApiError(500, $exception);
        }

        self::emitHtmlError($exception);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array((int) $error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            return;
        }

        $exception = new \ErrorException(
            (string) $error['message'],
            0,
            (int) $error['type'],
            (string) $error['file'],
            (int) $error['line']
        );

        self::logThrowable($exception, 'CRITICAL');
        self::captureSentryIfConfigured($exception);

        if (self::isApiRequest()) {
            self::emitJsonApiError(500, $exception);
        }

        self::emitHtmlError($exception);
    }

    private static function logThrowable(\Throwable $e, string $level): void
    {
        self::logStructured(
            $level,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        AppLogger::exception($e, [
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null,
        ]);
    }

    private static function logStructured(string $level, string $message, string $file, int $line, string $trace): void
    {
        $payload = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'trace' => $trace,
        ];

        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $lineJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($lineJson !== false) {
            @file_put_contents($dir . '/error-handler.jsonl', $lineJson . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private static function captureSentryIfConfigured(\Throwable $e): void
    {
        if (!function_exists('Sentry\\captureException')) {
            return;
        }

        $dsn = self::resolveSentryDsn();
        if ($dsn === null || $dsn === '') {
            return;
        }

        try {
            static $sentryInitialized = false;
            if (!$sentryInitialized) {
                \Sentry\init([
                    'dsn' => $dsn,
                    'environment' => strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')),
                ]);
                $sentryInitialized = true;
            }
            \Sentry\captureException($e);
        } catch (\Throwable $ignored) {
            // SDK absent ou init impossible (autoload, etc.)
        }
    }

    private static function resolveSentryDsn(): ?string
    {
        $fromEnv = $_ENV['SENTRY_DSN'] ?? getenv('SENTRY_DSN');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $configFile = __DIR__ . '/../config/sentry.php';
        if (is_file($configFile)) {
            $config = require $configFile;
            if (is_array($config) && !empty($config['dsn'])) {
                return (string) $config['dsn'];
            }
        }

        return null;
    }

    /**
     * @return never
     */
    private static function emitJsonApiError(int $code, ?\Throwable $e = null): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($code);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $body = [
            'success' => false,
            'error' => 'Erreur interne',
        ];

        if (self::isDev() && $e !== null) {
            $body['debug'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        echo json_encode($body, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @return never
     */
    private static function emitHtmlError(\Throwable $exception): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Erreur</title>
</head>
<body>
    <h1>Une erreur est survenue</h1>';

        if (self::isDev()) {
            echo '<pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . "\n"
                . htmlspecialchars($exception->getFile() . ':' . $exception->getLine(), ENT_QUOTES, 'UTF-8') . "\n"
                . htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            echo '<p>Nous avons été notifiés de cette erreur et allons la corriger rapidement.</p>';
        }

        echo '</body></html>';
        exit;
    }

    private static function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, '/API/')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'));
    }

    private static function isDev(): bool
    {
        $env = strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
        if ($env === 'local' || $env === 'development') {
            return true;
        }

        $debug = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG');
        return $debug === true || $debug === 1 || $debug === '1' || $debug === 'true' || $debug === 'TRUE';
    }

    private static function severityToLevelName(int $severity): string
    {
        return match ($severity) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE => 'CRITICAL',
            E_RECOVERABLE_ERROR, E_USER_ERROR => 'ERROR',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'WARNING',
            E_NOTICE, E_USER_NOTICE => 'NOTICE',
            default => 'INFO',
        };
    }

    private static function severityToMonologLevel(int $severity): int
    {
        return match ($severity) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE => \Monolog\Logger::CRITICAL,
            E_RECOVERABLE_ERROR, E_USER_ERROR => \Monolog\Logger::ERROR,
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => \Monolog\Logger::WARNING,
            E_NOTICE, E_USER_NOTICE => \Monolog\Logger::NOTICE,
            E_STRICT, E_DEPRECATED, E_USER_DEPRECATED => \Monolog\Logger::INFO,
            default => \Monolog\Logger::DEBUG,
        };
    }
}
