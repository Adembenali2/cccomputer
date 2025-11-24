<?php
// includes/security_headers.php
// Headers de sécurité HTTP pour protéger contre diverses attaques

// Empêcher le MIME type sniffing
header('X-Content-Type-Options: nosniff');

// Empêcher le clickjacking
header('X-Frame-Options: DENY');

// Protection XSS (navigateurs anciens)
header('X-XSS-Protection: 1; mode=block');

// Politique de référent (optionnel, à ajuster selon les besoins)
// header('Referrer-Policy: strict-origin-when-cross-origin');

// Détecter si on est en HTTPS
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
         || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
         || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// HSTS (HTTP Strict Transport Security) - seulement en HTTPS
if ($isSecure) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Content Security Policy (CSP) - à ajuster selon les besoins
// Cette politique est stricte, vous devrez peut-être l'ajuster
// Note: 'unsafe-eval' est nécessaire pour certaines bibliothèques JavaScript
// En production, essayez de l'enlever si possible
$csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none';";
header("Content-Security-Policy: {$csp}");

// Permissions Policy (anciennement Feature Policy)
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

