<?php
// public/forgot_password.php — [Fonctionnalité A] Demande de lien de réinitialisation
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/helpers.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$csrf = $_SESSION['csrf_token'] ?? '';
if ($csrf === '') {
    $csrf = $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) {
        $error = 'Session invalide. Rechargez la page.';
    } else {
        $emailInput = trim((string)($_POST['email'] ?? ''));
        // [Fonctionnalité A] Toujours le même message (anti-énumération)
        $message = 'Si cet email existe, un lien a été envoyé.';

        if ($emailInput !== '' && filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            try {
                $pdo = getPdo();
                $stmt = $pdo->prepare('SELECT id FROM utilisateurs WHERE Email = ? AND statut = ? LIMIT 1');
                $stmt->execute([$emailInput, 'actif']);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $token = bin2hex(random_bytes(32));
                    $ins = $pdo->prepare(
                        'INSERT INTO password_resets (email, token, used) VALUES (?, ?, 0)'
                    );
                    $ins->execute([$emailInput, $token]);

                    $appConfig = require __DIR__ . '/../config/app.php';
                    $baseUrl = (string)($appConfig['app_url'] ?? '');
                    if ($baseUrl === '') {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                            ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $baseUrl = $scheme . '://' . $host;
                    }
                    $resetUrl = $baseUrl . '/public/reset_password.php?token=' . rawurlencode($token);

                    try {
                        $mail = \App\Mail\MailerFactory::create($appConfig['email']);
                        $mail->addAddress($emailInput);
                        $mail->isHTML(true);
                        $mail->Subject = 'Réinitialisation de votre mot de passe CCComputer';
                        $mail->Body = '<p>Bonjour,</p><p>Pour définir un nouveau mot de passe, cliquez sur le lien ci-dessous '
                            . '(valide 1 heure) :</p><p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8')
                            . '">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
                            . '<p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.</p>';
                        $mail->AltBody = "Réinitialisation mot de passe : {$resetUrl}\n\nLien valide 1 heure.";
                        $mail->send();
                    } catch (\App\Mail\MailerException $e) {
                        error_log('[Fonctionnalité A] forgot_password mail: ' . $e->getMessage());
                    } catch (Throwable $e) {
                        error_log('[Fonctionnalité A] forgot_password mail: ' . $e->getMessage());
                    }
                }
            } catch (PDOException $e) {
                error_log('[Fonctionnalité A] forgot_password: ' . $e->getMessage());
            }
        }
    }
    // Réafficher un CSRF neuf après POST
    $csrf = $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Mot de passe oublié - CCComputer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="/assets/logos/logo.png">
  <link rel="stylesheet" href="/assets/css/login.css">
</head>
<body>
  <div class="login-wrapper">
    <img src="/assets/logos/logo.png" alt="Logo CCComputer" class="login-logo">
    <div class="login-title">Mot de passe oublié</div>

    <form class="login-form" method="post" action="/public/forgot_password.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="login-fields">
        <div>
          <label for="email">Adresse e-mail</label>
          <input type="email" id="email" name="email" placeholder="Votre e-mail" required autofocus>
        </div>
      </div>
      <button type="submit" class="login-btn">Envoyer le lien</button>
    </form>

    <a href="/public/login.php" class="forgot-link">Retour à la connexion</a>

    <?php if ($error): ?>
      <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($message): ?>
      <div class="login-error" style="color:#166534;background:#ecfdf5;border-color:#bbf7d0;"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
  </div>
</body>
</html>
