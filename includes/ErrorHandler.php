<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

/**
 * Gestionnaire d'erreurs global pour l'application
 * 
 * Capture les exceptions non gérées et les erreurs PHP
 * pour les logger proprement via Monolog
 * 
 * @package CCComputer
 */
class ErrorHandler
{
    private static bool $registered = false;
    
    /**
     * Enregistre les gestionnaires d'erreurs globaux
     * 
     * @return void
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        
        // Gestionnaire d'exceptions non capturées
        set_exception_handler([self::class, 'handleException']);
        
        // Gestionnaire d'erreurs PHP
        set_error_handler([self::class, 'handleError']);
        
        // Gestionnaire d'erreurs fatales
        register_shutdown_function([self::class, 'handleShutdown']);
        
        self::$registered = true;
    }
    
    /**
     * Gère les exceptions non capturées
     * 
     * @param \Throwable $exception
     * @return void
     */
    public static function handleException(\Throwable $exception): void
    {
        // Logger l'exception
        AppLogger::exception($exception, [
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null,
        ]);
        
        // Si c'est une requête API, retourner une réponse JSON
        if (self::isApiRequest()) {
            self::sendJsonError($exception);
        } else {
            // Sinon, afficher une page d'erreur générique
            self::sendHtmlError($exception);
        }
    }
    
    /**
     * Gère les erreurs PHP (warnings, notices, etc.)
     * 
     * @param int $severity
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Ne pas gérer les erreurs qui sont supprimées par l'opérateur @
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $level = self::severityToLogLevel($severity);
        
        AppLogger::getLogger()->log($level, $message, [
            'file' => $file,
            'line' => $line,
            'severity' => $severity,
        ]);
        
        // Si c'est une erreur fatale, on laisse PHP la gérer normalement
        if ($severity === E_ERROR || $severity === E_CORE_ERROR || $severity === E_COMPILE_ERROR) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Gère les erreurs fatales (shutdown)
     * 
     * @return void
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            AppLogger::critical('Fatal error: ' . $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        }
    }
    
    /**
     * Convertit le niveau de sévérité PHP en niveau Monolog
     * 
     * @param int $severity
     * @return int
     */
    private static function severityToLogLevel(int $severity): int
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
    
    /**
     * Vérifie si la requête actuelle est une requête API
     * 
     * @return bool
     */
    private static function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/API/') !== false || 
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }
    
    /**
     * Envoie une réponse JSON d'erreur
     * 
     * @param \Throwable $exception
     * @return void
     */
    private static function sendJsonError(\Throwable $exception): void
    {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = [
            'ok' => false,
            'error' => 'Une erreur interne est survenue',
        ];
        
        // En développement, inclure plus de détails
        if (self::isDevelopment()) {
            $response['debug'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Envoie une page HTML d'erreur
     * 
     * @param \Throwable $exception
     * @return void
     */
    private static function sendHtmlError(\Throwable $exception): void
    {
        http_response_code(500);
        
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        
        // Page d'erreur simple (peut être personnalisée)
        echo '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Erreur</title>
</head>
<body>
    <h1>Une erreur est survenue</h1>
    <p>Nous avons été notifiés de cette erreur et allons la corriger rapidement.</p>';
        
        if (self::isDevelopment()) {
            echo '<pre>' . htmlspecialchars($exception->getMessage()) . '</pre>';
        }
        
        echo '</body>
</html>';
        exit;
    }
    
    /**
     * Vérifie si on est en environnement de développement
     * 
     * @return bool
     */
    private static function isDevelopment(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'production') === 'development';
    }
}
