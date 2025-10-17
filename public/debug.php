<?php
// On démarre la session pour pouvoir lire son contenu
session_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Debug de Session</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f4; padding: 20px; }
        pre { background-color: #fff; border: 1px solid #ccc; padding: 15px; border-radius: 5px; white-space: pre-wrap; }
        h1 { color: #333; }
    </style>
</head>
<body>
    <h1>Contenu actuel de la variable $_SESSION</h1>
    
    <?php if (empty($_SESSION)): ?>
        <p>La session est vide.</p>
    <?php else: ?>
        <pre><?php print_r($_SESSION); ?></pre>
    <?php endif; ?>

    <br>
    <a href="public/login.php">Retour à la connexion</a>
</body>
</html>