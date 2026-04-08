<?php
// includes/session_config.php

// Détecter HTTPS derrière reverse proxy (Railway)
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Cookies de session sûrs et valables sur TOUT le site
// Détecter si on est en HTTPS (production Railway) ou HTTP (local)
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
         || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
         || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// Détecter si on est derrière un proxy (Railway)
// Railway utilise X-Forwarded-Proto et peut nécessiter SameSite=None
$isBehindProxy = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) 
              || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
              || !empty($_SERVER['HTTP_X_REAL_IP']);

// Sur Railway avec HTTPS derrière proxy, utiliser SameSite=None avec Secure
// En local HTTP, utiliser SameSite=Lax
// En production HTTPS direct, utiliser SameSite=Lax
$sameSite = ($isSecure && $isBehindProxy) ? 'None' : 'Lax';

// Nom de session personnalisé (DOIT être défini AVANT session_set_cookie_params et session_start)
// IMPORTANT: Ce nom doit être identique à celui du bootstrap API pour partager la même session
session_name('cc_sess');

session_set_cookie_params([
  'lifetime' => 0,             // cookie de session
  'path'     => '/',           // ⬅ IMPORTANT: pas de sous-chemin
  'domain'   => '',            // laisser vide (Railway gère le domaine)
  'secure'   => $isSecure,     // HTTPS en production, HTTP en local
  'httponly' => true,
  'samesite' => $sameSite,    // None si HTTPS+proxy (Railway), Lax sinon
]);

// Configuration PHP ini pour les cookies de session (compatible Railway)
ini_set('session.cookie_secure', $isSecure ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', $sameSite);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Démarre la session si nécessaire
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
