<?php
// /redirection/erreur_connexion.php
// Page d'attente après échec de connexion
// 401 = non authentifié
http_response_code(401);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Erreur de connexion | CCComputer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Fallback si JS désactivé -->
  <noscript>
    <meta http-equiv="refresh" content="0;url=/public/login.php">
  </noscript>

  <style>
    html, body { height: 100%; margin: 0; padding: 0; }
    body {
      display: flex; justify-content: center; align-items: center;
      height: 100vh; background: #f1f5f9; flex-direction: column;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans";
    }
    .logo-container {
      animation: pop 1.2s cubic-bezier(.68,-0.55,.27,1.55) forwards,
                 fadeOut 0.7s 2.3s ease-in forwards;
    }
    .logo-img {
      width: 110px; height: 110px; object-fit: contain; display: block; margin: 0 auto;
      filter: drop-shadow(0 4px 24px #3b82f6aa);
    }
    .error-message {
      margin-top: 40px; font-size: 1.05rem; color: #dc2626; font-weight: 600; letter-spacing: .01em;
      text-align: center; background: #fff; padding: 14px 26px; border-radius: 12px; box-shadow: 0 2px 12px #0001;
      animation: fadeIn 1.1s cubic-bezier(.42,0,.48,1.51);
    }
    .link-now { margin-top: 14px; font-size: .95rem; }
    .link-now a { color: #2563eb; text-decoration: none; }
    .link-now a:hover { text-decoration: underline; }

    @keyframes pop {
      0% { transform: scale(0.3) rotate(-30deg); opacity: 0; }
      60% { transform: scale(1.1) rotate(10deg); opacity: 1;}
      80% { transform: scale(0.97) rotate(-4deg);}
      100% { transform: scale(1) rotate(0);}
    }
    @keyframes fadeOut { to { opacity: 0; } }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(30px);} to { opacity: 1; transform: translateY(0);} }
  </style>

  <script>
    // Redirection après 3 secondes vers la page de connexion
    setTimeout(function () {
      window.location.href = "/public/login.php";
    }, 3000);
  </script>
</head>
<body>
  <div class="logo-container" aria-hidden="true">
    <img src="/assets/logos/logo.png" alt="Logo CCComputer" class="logo-img">
  </div>

  <div class="error-message" role="alert" aria-live="assertive">
    Adresse e-mail ou mot de passe incorrect.<br>
    Vous allez être redirigé…
  </div>

  <div class="link-now">
    <a href="/public/login.php">Y aller maintenant</a>
  </div>
</body>
</html>
