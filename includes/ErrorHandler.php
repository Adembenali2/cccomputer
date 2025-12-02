<?php
declare(strict_types=1);

/**
 * Gestionnaire d'erreurs centralisé
 * Remplace les try/catch dispersés avec des messages standardisés
 */
class ErrorHandler
{
    /**
     * Initialise les gestionnaires d'erreurs globaux
     * 
     * @return void
     */
    public static function init(): void
    {
        // Gestionnaire d'exceptions non capturées
        set_exception_handler([self::class, 'handleException']);
        
        // Gestionnaire d'erreurs fatales
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    /**
     * Gère les exceptions non capturées
     * 
     * @param Throwable $e Exception non capturée
     * @return void
     */
    public static function handleException(Throwable $e): void
    {
        error_log(
            sprintf(
                'Uncaught exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            )
        );
        error_log('Stack trace: ' . $e->getTraceAsString());
        
        // En mode CLI, afficher directement
        if (php_sapi_name() === 'cli') {
            echo "Error: " . $e->getMessage() . "\n";
            return;
        }
        
        // En mode web, renvoyer une réponse JSON si possible
        if (function_exists('jsonResponse')) {
            jsonResponse([
                'ok' => false,
                'error' => 'Erreur serveur'
            ], 500);
        } else {
            http_response_code(500);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Erreur interne du serveur';
        }
    }
    
    /**
     * Gère les erreurs fatales (shutdown)
     * 
     * @return void
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log(
                sprintf(
                    'Fatal error: %s in %s:%d',
                    $error['message'],
                    $error['file'],
                    $error['line']
                )
            );
        }
    }
    
    /**
     * Formate un message d'erreur pour l'utilisateur
     * 
     * @param string $message Message technique
     * @param bool $includeDetails Inclure les détails techniques
     * @return string Message formaté
     */
    public static function formatUserMessage(string $message, bool $includeDetails = false): string
    {
        // Messages génériques pour l'utilisateur
        $userMessages = [
            'SQLSTATE' => 'Erreur de base de données',
            'PDOException' => 'Erreur de base de données',
            'Connection' => 'Erreur de connexion',
        ];
        
        foreach ($userMessages as $key => $userMsg) {
            if (stripos($message, $key) !== false) {
                return $userMsg;
            }
        }
        
        return $includeDetails ? $message : 'Une erreur est survenue';
    }
}

