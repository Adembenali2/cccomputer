<?php
// includes/session_config.php

// Cookies de session sûrs et valables sur TOUT le site
session_set_cookie_params([
  'lifetime' => 0,             // cookie de session
  'path'     => '/',           // ⬅ IMPORTANT: pas de sous-chemin
  'domain'   => '',            // laisser vide (Railway gère le domaine)
  'secure'   => true,          // derrière proxy HTTPS
  'httponly' => true,
  'samesite' => 'Lax',
]);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Démarre la session si nécessaire
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
