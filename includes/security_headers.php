<?php
// includes/security_headers.php
// Headers de sécurité HTTP pour protéger contre diverses attaques

header_remove('X-Powered-By');

// Nonce CSP unique par requête (une seule fois ; security_headers peut être inclus plusieurs fois)
if (empty($GLOBALS['csp_nonce'])) {
    $GLOBALS['csp_nonce'] = bin2hex(random_bytes(16));
}
$cspNonce = $GLOBALS['csp_nonce'];

// Empêcher le MIME type sniffing
header('X-Content-Type-Options: nosniff');

// Empêcher le clickjacking
header('X-Frame-Options: DENY');

// Protection XSS (navigateurs anciens)
header('X-XSS-Protection: 1; mode=block');

header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');

header('Permissions-Policy: camera=(), microphone=(), geolocation=(self), payment=()');

// Détecter si on est en HTTPS (dont proxy Railway / X-Forwarded-Proto)
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
         || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// HSTS (HTTP Strict Transport Security) - seulement en HTTPS
if ($isSecure) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Content Security Policy stricte (nonce pour scripts inline)
$csp = implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'nonce-{$cspNonce}' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self'",
    "connect-src 'self'",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "base-uri 'self'",
]);
header("Content-Security-Policy: {$csp}");
