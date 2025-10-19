<?php
// index.php (racine)

// 1) Démarrer la session avec les bons paramètres (cookie path="/", secure, etc.)
require_once __DIR__ . '/includes/session_config.php';

// 2) Choisir la destination selon l'état de connexion
$redirectUrl = !empty($_SESSION['user_id'])
    ? '/public/dashboard.php'
    : '/public/login.php';

// (Option robuste : rediriger côté serveur tout de suite. Décommente si tu veux zapper le splash.)
// header('Location: ' . $redirectUrl, true, 302);
// exit;
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
