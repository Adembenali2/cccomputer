<?php
declare(strict_types=1);

/**
 * Mot de passe oublié — envoi aligné sur InvoiceEmailService :
 * Brevo API si BREVO_API_KEY, sinon MailerService (SMTP via MailerFactory).
 */

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Mail\BrevoApiMailerService;
use App\Mail\MailerException;
use App\Mail\MailerService;

$error = '';
$success = '';
$csrf = $_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $error = 'Session invalide. Recommencez.';
    } else {
        $email = trim((string)filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse email invalide.';
        } else {
            $pdo = getPdo();

            $stmt = $pdo->prepare(
                "SELECT id, nom, prenom FROM utilisateurs WHERE Email = ? AND statut = 'actif' LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = bin2hex(random_bytes(32));

                $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

                $pdo->prepare(
                    'INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())'
                )->execute([$email, $token]);

                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                    ? 'https' : 'http';
                $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetLink = $protocol . '://' . $domain . '/public/reset_password.php?token=' . urlencode($token);

                $appConfig = require __DIR__ . '/../config/app.php';
                $emailConfig = $appConfig['email'] ?? [];

                $subject = 'Réinitialisation de votre mot de passe - CCComputer';
                $prenomPlain = trim((string)($user['prenom'] ?? ''));
                $prenom = htmlspecialchars($prenomPlain, ENT_QUOTES, 'UTF-8');
                $resetLinkEsc = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

                $mailBodyHtml = '
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:20px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
    <h2 style="color:#111827;margin-top:0;">Réinitialisation du mot de passe</h2>
    <p style="color:#374151;">Bonjour ' . $prenom . ',</p>
    <p style="color:#374151;">Vous avez demandé à réinitialiser votre mot de passe. Cliquez sur le bouton ci-dessous :</p>
    <div style="text-align:center;margin:28px 0;">
      <a href="' . $resetLinkEsc . '" style="background:#2563eb;color:#fff;padding:14px 28px;
         border-radius:7px;text-decoration:none;font-weight:bold;font-size:15px;display:inline-block;">
        Réinitialiser mon mot de passe
      </a>
    </div>
    <p style="color:#6b7280;font-size:0.85rem;">Ce lien est valable pendant <strong>1 heure</strong>.</p>
    <p style="color:#6b7280;font-size:0.85rem;">Si vous n\'avez pas fait cette demande, ignorez cet email.</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">
    <p style="color:#9ca3af;font-size:0.78rem;text-align:center;">CCComputer — Application interne</p>
  </div>
</body>
</html>';

                $altBody = 'Bonjour ' . $prenomPlain . ",\n\nRéinitialisez votre mot de passe via ce lien (valable 1h) :\n"
                    . $resetLink . "\n\nSi vous n'avez pas fait cette demande, ignorez cet email.";

                try {
                    if (!empty($_ENV['BREVO_API_KEY'])) {
                        error_log('[forgot_password] Envoi via API Brevo (même fil que InvoiceEmailService)');
                        $brevoService = new BrevoApiMailerService();
                        $brevoService->sendEmailWithPdf($email, $subject, $altBody, null, null, $mailBodyHtml);
                    } else {
                        error_log('[forgot_password] Envoi via SMTP MailerService (même fil que InvoiceEmailService)');
                        $mailerService = new MailerService($emailConfig);
                        $mailerService->sendEmail($email, $subject, $altBody, $mailBodyHtml);
                    }
                    error_log('[forgot_password] Email envoyé à ' . $email);
                } catch (MailerException $e) {
                    error_log('[forgot_password] ERREUR envoi email à ' . $email . ' : ' . $e->getMessage());
                } catch (\Throwable $e) {
                    error_log('[forgot_password] ERREUR envoi email à ' . $email . ' : ' . $e->getMessage());
                }
            }

            $success = 'Si cette adresse email existe dans notre système, vous recevrez un lien de réinitialisation dans quelques minutes.';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $csrf = $_SESSION['csrf_token'];
        }
    }
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
    <?php if ($success): ?>
      <div class="login-success" style="background:#d1fae5;color:#065f46;padding:12px 16px;
           border-radius:7px;margin-bottom:16px;font-size:0.9rem;text-align:center;">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <p style="text-align:center;margin-top:16px;">
        <a href="/public/login.php" class="forgot-link">Retour à la connexion</a>
      </p>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <form class="login-form" method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="login-fields">
          <div>
            <label for="email">Votre adresse e-mail</label>
            <input type="email" id="email" name="email" placeholder="email@exemple.com" required autofocus>
          </div>
        </div>
        <button type="submit" class="login-btn">Envoyer le lien</button>
      </form>
      <p style="text-align:center;margin-top:16px;">
        <a href="/public/login.php" class="forgot-link">Retour à la connexion</a>
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
