<?php
// public/reset_password.php — [Fonctionnalité A] Nouveau mot de passe via token
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/helpers.php';

$tokenGet = isset($_GET['token']) ? preg_replace('/[^a-f0-9]/i', '', (string)$_GET['token']) : '';
if (strlen($tokenGet) !== 64) {
    $tokenGet = '';
}

$csrf = $_SESSION['csrf_token'] ?? '';
if ($csrf === '') {
    $csrf = $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = null;
$tokenRow = null;
$error = '';
$showForm = false;

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    $error = 'Service temporairement indisponible.';
}

if ($pdo && $tokenGet !== '') {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, email, created_at, used FROM password_resets WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$tokenGet]);
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Fonctionnalité A] reset_password fetch: ' . $e->getMessage());
        $error = 'Lien invalide ou expiré.';
    }
}

$validToken = false;
if ($tokenRow && (int)($tokenRow['used'] ?? 1) === 0 && !empty($tokenRow['created_at'])) {
    $created = strtotime((string)$tokenRow['created_at']);
    if ($created !== false && (time() - $created) <= 3600) {
        $validToken = true;
    }
}

if ($tokenGet === '') {
    $error = 'Lien invalide ou expiré.';
} elseif (!$pdo) {
    // déjà défini
} elseif (!$validToken) {
    $error = 'Lien invalide ou expiré.';
} else {
    $showForm = true;
}

if ($showForm && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) {
        $error = 'Session invalide. Rechargez la page.';
        $showForm = true;
    } else {
        $pass = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');
        if (strlen($pass) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($pass !== $pass2) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, email, used, created_at FROM password_resets WHERE token = ? LIMIT 1'
                );
                $stmt->execute([$tokenGet]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $ok = $row && (int)$row['used'] === 0;
                $created = $row ? strtotime((string)$row['created_at']) : false;
                if (!$ok || $created === false || (time() - $created) > 3600) {
                    $error = 'Lien invalide ou expiré.';
                    $showForm = false;
                } else {
                    $emailReset = (string)$row['email'];
                    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
                    $upd = $pdo->prepare('UPDATE utilisateurs SET password = ? WHERE Email = ? AND statut = ?');
                    $upd->execute([$hash, $emailReset, 'actif']);
                    $mark = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?');
                    $mark->execute([$tokenGet]);
                    $_SESSION['login_success'] = 'Votre mot de passe a été mis à jour. Vous pouvez vous connecter.';
                    header('Location: /public/login.php', true, 302);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('[Fonctionnalité A] reset_password post: ' . $e->getMessage());
                $error = 'Une erreur est survenue. Réessayez plus tard.';
            }
        }
    }
    $csrf = $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Nouveau mot de passe - CCComputer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="/assets/logos/logo.png">
  <link rel="stylesheet" href="/assets/css/login.css">
</head>
<body>
  <div class="login-wrapper">
    <img src="/assets/logos/logo.png" alt="Logo CCComputer" class="login-logo">
    <div class="login-title">Nouveau mot de passe</div>

    <?php if ($showForm): ?>
    <form class="login-form" method="post" action="/public/reset_password.php?token=<?= htmlspecialchars($tokenGet, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="login-fields">
        <div>
          <label for="password">Nouveau mot de passe (min. 8)</label>
          <input type="password" id="password" name="password" minlength="8" required>
        </div>
        <div>
          <label for="password_confirm">Confirmation</label>
          <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
        </div>
      </div>
      <button type="submit" class="login-btn">Enregistrer</button>
    </form>
    <?php else: ?>
    <p style="text-align:center;color:#64748b;max-width:320px;"><?= htmlspecialchars($error ?: 'Lien invalide.', ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <a href="/public/login.php" class="forgot-link">Retour à la connexion</a>

    <?php if ($showForm && $error): ?>
      <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
  </div>
</body>
</html>
