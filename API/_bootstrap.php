<?php
// API/_bootstrap.php
// Bootstrap centralisé pour tous les endpoints API
// DOIT être inclus AVANT tout output (même un espace ou saut de ligne)

// Détecter HTTPS derrière reverse proxy (Railway)
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Détecter si on est en HTTPS (production Railway) ou HTTP (local)
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
         || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
         || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// Détecter si on est derrière un proxy (Railway)
$isBehindProxy = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) 
              || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
              || !empty($_SERVER['HTTP_X_REAL_IP']);

// Sur Railway avec HTTPS derrière proxy, utiliser SameSite=None avec Secure
// En local HTTP, utiliser SameSite=Lax
// En production HTTPS direct, utiliser SameSite=Lax
$sameSite = ($isSecure && $isBehindProxy) ? 'None' : 'Lax';

// Configuration des cookies de session pour Railway HTTPS
session_set_cookie_params([
    'lifetime' => 0,             // cookie de session
    'path'     => '/',           // IMPORTANT: pas de sous-chemin
    'domain'   => '',            // laisser vide (Railway gère le domaine)
    'secure'   => $isSecure,     // HTTPS en production, HTTP en local
    'httponly' => true,
    'samesite' => $sameSite,     // None si HTTPS+proxy (Railway), Lax sinon
]);

// Configuration PHP ini pour les cookies de session
ini_set('session.cookie_secure', $isSecure ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', $sameSite);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Nom de session personnalisé (optionnel mais recommandé)
session_name('cc_sess');

// Démarrer la session si elle n'est pas déjà active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

