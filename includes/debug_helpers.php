<?php
declare(strict_types=1);

/**
 * includes/debug_helpers.php
 * Fonctions helper pour le débogage et les logs
 * 
 * Ces fonctions sont utilisées pour les scripts d'import et de diagnostic
 */

/**
 * Log un message de debug avec timestamp et contexte
 * 
 * @param string $message Message à logger
 * @param array $context Contexte additionnel (sera encodé en JSON)
 * @param string|null $tag Tag optionnel pour identifier la source (ex: "run_import_if_due", "PID:123")
 * @param bool $echo Si true, affiche aussi le message (utile pour les scripts en temps réel)
 * @return void
 */
if (!function_exists('debugLog')) {
    function debugLog(string $message, array $context = [], ?string $tag = null, bool $echo = false): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $tagStr = $tag ? "[$tag] " : '';
        $logMsg = "[$timestamp] $tagStr$message$contextStr\n";
        
        if ($echo) {
            echo $logMsg;
        }
        error_log($logMsg);
    }
}

