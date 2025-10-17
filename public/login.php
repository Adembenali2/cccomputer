<?php
// /public/login.php
session_start();
$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion - CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>
    <div class="login-wrapper">
        <img src="../assets/logos/logo.png" alt="Logo CCComputer" class="login-logo">
        <div class="login-title">Connexion à CCComputer</div>

        <form class="login-form" action="/source/connexion/login_process.php" method="post" ...>
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

        <?php if($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>