<?php
// public/login.php
require_once __DIR__ . '/../includes/session_config.php';

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
// [Fonctionnalité A] Message après réinitialisation du mot de passe
$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_success']);

// Protection brute-force : lecture seule du compteur (sans appeler checkRateLimit, pour ne pas incrémenter au chargement de la page)
$loginRateLimitKey = 'login_attempt_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$loginRateLimitKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $loginRateLimitKey);
$loginIpBlocked = false;
if (function_exists('apcu_fetch')) {
    $loginIpBlocked = ((int) apcu_fetch('ratelimit_' . $loginRateLimitKey)) >= 5;
} else {
    $loginRlFile = __DIR__ . '/../cache/ratelimit/' . md5($loginRateLimitKey) . '.json';
    if (is_file($loginRlFile)) {
        $loginRlRaw = @file_get_contents($loginRlFile);
        if ($loginRlRaw !== false) {
            $loginRlData = @json_decode($loginRlRaw, true);
            if (is_array($loginRlData) && isset($loginRlData['count'], $loginRlData['reset'])) {
                $loginRlNow = time();
                if ($loginRlNow < (int) $loginRlData['reset'] && (int) $loginRlData['count'] >= 5) {
                    $loginIpBlocked = true;
                }
            }
        }
    }
}

$csrf = $_SESSION['csrf_token'] ?? '';
if ($csrf === '') {
    $csrf = $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Connexion - CCComputer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="/assets/logos/logo.png">
  <link rel="stylesheet" href="/assets/css/login.css">
</head>
<body>
  <div class="login-wrapper">
    <img src="/assets/logos/logo.png" alt="Logo CCComputer" class="login-logo">
    <div class="login-title">Connexion à CCComputer</div>

    <form class="login-form" action="/source/connexion/login_process.php" method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="login-fields">
        <div>
          <label for="email">Adresse e-mail</label>
          <input type="email" id="email" name="email" placeholder="Votre e-mail" required autofocus>
        </div>
        <div>
          <label for="password">Mot de passe</label>
          <input type="password" id="password" name="password" placeholder="Votre mot de passe" required>
        </div>
      </div>
      <button type="submit" class="login-btn">Connexion</button>
    </form>

    <?php if ($loginIpBlocked): ?>
      <div class="login-error">Trop de tentatives. Réessayez dans 10 minutes.</div>
    <?php elseif ($success): ?>
      <div class="login-error login-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($error): ?>
      <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
  </div>
</body>
</html>
