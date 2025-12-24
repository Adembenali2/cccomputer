<?php
// index.php (racine)

/**
 * Bypass pour servir les fichiers statiques et endpoints API
 * Doit être exécuté AVANT toute autre logique
 */

/**
 * Envoie un fichier avec le bon Content-Type
 */
function sendFile(string $filePath): void
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return;
    }
    
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'txt' => 'text/plain',
        'json' => 'application/json',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'html' => 'text/html',
        'xml' => 'application/xml',
    ];
    
    $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $contentType . '; charset=utf-8');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// Récupérer REQUEST_URI sans query string
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Bypass explicite pour l'endpoint test_smtp.php
if ($requestUri === '/test_smtp.php') {
    $testSmtpFile = __DIR__ . '/public/test_smtp.php';
    if (file_exists($testSmtpFile)) {
        require $testSmtpFile;
        exit;
    }
}

// Bypass optionnel pour /API/test_smtp.php
if ($requestUri === '/API/test_smtp.php' || $requestUri === '/api/test_smtp.php') {
    $testSmtpApiFile = __DIR__ . '/public/API/test_smtp.php';
    if (file_exists($testSmtpApiFile)) {
        require $testSmtpApiFile;
        exit;
    }
}

// Bypass pour servir les fichiers statiques dans public/
if ($requestUri !== '/') {
    // Essayer d'abord dans public/
    $publicFile = __DIR__ . '/public' . $requestUri;
    if (file_exists($publicFile) && is_file($publicFile)) {
        $extension = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
        // Servir les fichiers non-PHP (les fichiers PHP sont gérés par les bypass explicites ci-dessus)
        if ($extension !== 'php') {
            sendFile($publicFile);
        }
    }
    
    // Essayer aussi à la racine (si le document root est la racine du projet)
    $rootFile = __DIR__ . $requestUri;
    if (file_exists($rootFile) && is_file($rootFile)) {
        $extension = strtolower(pathinfo($rootFile, PATHINFO_EXTENSION));
        // Servir les fichiers non-PHP (les fichiers PHP sont gérés par les bypass explicites ci-dessus)
        if ($extension !== 'php') {
            sendFile($rootFile);
        }
    }
}

// 1) Démarrer la session avec les bons paramètres (cookie path="/", secure, etc.)
require_once __DIR__ . '/includes/session_config.php';

// 2) Headers de sécurité
require_once __DIR__ . '/includes/security_headers.php';

// 2) Choisir la destination selon l'état de connexion
$redirectUrl = !empty($_SESSION['user_id'])
    ? '/public/dashboard.php'
    : '/public/login.php';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Bienvenue | CCComputer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Fallback si JS désactivé -->
  <noscript>
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>">
  </noscript>

  <style>
    html, body { height: 100%; margin: 0; padding: 0; }
    body {
      display: flex; justify-content: center; align-items: center;
      height: 100vh; background: #f1f5f9;
    }
    .logo-container {
      animation: pop 1.2s cubic-bezier(.68,-0.55,.27,1.55) forwards,
                 fadeOut 0.7s 2.3s ease-in forwards;
    }
    .logo-img {
      width: 110px; height: 110px; object-fit: contain; display: block; margin: 0 auto;
      filter: drop-shadow(0 4px 24px #3b82f6aa);
    }
    @keyframes pop {
      0% { transform: scale(0.3) rotate(-30deg); opacity: 0; }
      60% { transform: scale(1.1) rotate(10deg); opacity: 1; }
      80% { transform: scale(0.97) rotate(-4deg); }
      100% { transform: scale(1) rotate(0); }
    }
    @keyframes fadeOut { to { opacity: 0; } }
  </style>

  <script>
    // Redirection après 3 secondes vers la destination choisie côté PHP
    setTimeout(function () {
      window.location.href = "<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>";
    }, 3000);
  </script>
</head>
<body>
  <div class="logo-container">
    <!-- Chemin ABSOLU vers l'asset -->
    <img src="/assets/logos/logo.png" alt="Logo CCComputer" class="logo-img">
  </div>
</body>
</html>
