<?php

declare(strict_types=1);

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryUsageProcessor;

/**
 * Logger centralisé pour l'application
 * 
 * Utilise Monolog pour un système de logs professionnel
 * Supporte plusieurs niveaux : DEBUG, INFO, WARNING, ERROR, CRITICAL
 * 
 * @package CCComputer
 */
class AppLogger
{
    private static ?MonologLogger $instance = null;
    private static ?MonologLogger $sentryLogger = null;
    
    /**
     * Récupère l'instance du logger
     * 
     * @return MonologLogger
     */
    public static function getLogger(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = self::createLogger();
        }
        
        return self::$instance;
    }
    
    /**
     * Crée et configure le logger
     * 
     * @return MonologLogger
     */
    private static function createLogger(): MonologLogger
    {
        $logger = new MonologLogger('cccomputer');
        
        // Créer le dossier logs s'il n'existe pas
        $logsDir = __DIR__ . '/../logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        
        // Handler pour les logs quotidiens (rotation automatique)
        $fileHandler = new RotatingFileHandler(
            $logsDir . '/app.log',
            30, // Garder 30 jours de logs
            MonologLogger::DEBUG
        );
        
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);
        
        // Handler pour les erreurs critiques (fichier séparé)
        $errorHandler = new StreamHandler(
            $logsDir . '/error.log',
            MonologLogger::ERROR
        );
        $errorHandler->setFormatter($formatter);
        $logger->pushHandler($errorHandler);
        
        // Ajouter des processeurs pour plus d'informations
        $logger->pushProcessor(new IntrospectionProcessor(MonologLogger::DEBUG, ['Monolog\\']));
        $logger->pushProcessor(new MemoryUsageProcessor());
        
        // Intégration Sentry (optionnelle)
        self::setupSentry($logger);
        
        return $logger;
    }
    
    /**
     * Configure l'intégration Sentry si la DSN est disponible
     * 
     * @param MonologLogger $logger
     * @return void
     */
    private static function setupSentry(MonologLogger $logger): void
    {
        // Vérifier si Sentry est disponible et configuré
        $sentryDsn = self::getSentryDsn();
        
        if ($sentryDsn && class_exists('\Sentry\ClientBuilder')) {
            try {
                \Sentry\init([
                    'dsn' => $sentryDsn,
                    'environment' => self::getEnvironment(),
                    'traces_sample_rate' => 0.1, // 10% des transactions pour le tracing
                ]);
                
                // Handler Sentry pour les erreurs critiques uniquement
                $sentryHandler = new \Monolog\Handler\SentryHandler(
                    \Sentry\State\Hub::getCurrent(),
                    MonologLogger::ERROR
                );
                $logger->pushHandler($sentryHandler);
                
                self::$sentryLogger = $logger;
            } catch (\Throwable $e) {
                // Si Sentry échoue, on continue sans Sentry
                error_log('Sentry initialization failed: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Récupère la DSN Sentry depuis la configuration ou les variables d'environnement
     * 
     * @return string|null
     */
    private static function getSentryDsn(): ?string
    {
        // Priorité 1: Variable d'environnement
        if (isset($_ENV['SENTRY_DSN']) && !empty($_ENV['SENTRY_DSN'])) {
            return $_ENV['SENTRY_DSN'];
        }
        
        // Priorité 2: Fichier de configuration
        $configFile = __DIR__ . '/../config/sentry.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            return $config['dsn'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Détermine l'environnement (production, development, testing)
     * 
     * @return string
     */
    private static function getEnvironment(): string
    {
        return $_ENV['APP_ENV'] ?? 'production';
    }
    
    /**
     * Log un message de niveau DEBUG
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getLogger()->debug($message, $context);
    }
    
    /**
     * Log un message de niveau INFO
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::getLogger()->info($message, $context);
    }
    
    /**
     * Log un message de niveau WARNING
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getLogger()->warning($message, $context);
    }
    
    /**
     * Log un message de niveau ERROR
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::getLogger()->error($message, $context);
    }
    
    /**
     * Log un message de niveau CRITICAL
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function critical(string $message, array $context = []): void
    {
        self::getLogger()->critical($message, $context);
    }
    
    /**
     * Log une exception
     * 
     * @param \Throwable $exception
     * @param array $context
     * @return void
     */
    public static function exception(\Throwable $exception, array $context = []): void
    {
        self::getLogger()->error(
            $exception->getMessage(),
            array_merge($context, [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ])
        );
    }
}

