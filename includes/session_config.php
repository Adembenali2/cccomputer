<?php
// includes/session_config.php

// Cookies de session sûrs et valables sur TOUT le site
// Détecter si on est en HTTPS (production) ou HTTP (local)
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
         || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
         || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

session_set_cookie_params([
  'lifetime' => 0,             // cookie de session
  'path'     => '/',           // ⬅ IMPORTANT: pas de sous-chemin
  'domain'   => '',            // laisser vide (Railway gère le domaine)
  'secure'   => $isSecure,      // HTTPS en production, HTTP en local
  'httponly' => true,
  'samesite' => 'Lax',
]);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Démarre la session si nécessaire
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
