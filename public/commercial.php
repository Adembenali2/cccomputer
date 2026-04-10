<?php
// /public/commercial.php - Espace commercial (placeholder)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
requireCommercial();
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security_headers.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace commercial - CCComputer</title>
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/header.css">
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="main-content" style="padding: 2rem; max-width: 600px; margin: 0 auto;">
    <h1>Espace commercial</h1>
    <p style="color: var(--text-secondary, #64748b); margin-top: 1rem;">
        Cette section est en cours de développement. Elle sera prochainement disponible.
    </p>
    <p style="margin-top: 1.5rem;">
        <a href="/public/dashboard.php" class="btn btn-primary">Retour au tableau de bord</a>
    </p>
</main>
</body>
</html>
