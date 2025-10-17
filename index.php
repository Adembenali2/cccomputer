<?php
// NOUVELLE PARTIE PHP : DÉFINIR LA DESTINATION
// Cette page est maintenant publique, on ne met plus "auth.php" ici.

// 1. On démarre la session pour vérifier si l'utilisateur est connecté.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. On choisit la bonne URL de redirection.
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    // L'utilisateur est connecté -> on le redirigera vers le tableau de bord.
    $redirectUrl = 'public/dashboard.php';
} else {
    // L'utilisateur N'EST PAS connecté -> on le redirigera vers la page de connexion.
    // IMPORTANT : Vérifiez que ce chemin est correct.
    $redirectUrl = 'public/login.php';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bienvenue | CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Votre CSS reste identique, il est parfait. */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background: #f1f5f9;
        }
        .logo-container {
            animation: pop 1.2s cubic-bezier(.68,-0.55,.27,1.55) forwards, fadeOut 0.7s 2.3s ease-in forwards;
        }
        .logo-img {
            width: 110px;
            height: 110px;
            object-fit: contain;
            display: block;
            margin: 0 auto;
            filter: drop-shadow(0 4px 24px #3b82f6aa);
        }
        @keyframes pop {
            0% { transform: scale(0.3) rotate(-30deg); opacity: 0; }
            60% { transform: scale(1.1) rotate(10deg); opacity: 1;}
            80% { transform: scale(0.97) rotate(-4deg);}
            100% { transform: scale(1) rotate(0);}
        }
        @keyframes fadeOut {
            to { opacity: 0; }
        }
    </style>
    <script>
        // MODIFICATION ICI : La redirection est maintenant dynamique
        setTimeout(function() {
            // JavaScript utilise la variable $redirectUrl définie par PHP
            window.location.href = "<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>";
        }, 3000); // Redirection après 3 secondes
    </script>
</head>
<body>
    <div class="logo-container">
        <img src="assets/logos/logo.png" alt="Logo CCComputer" class="logo-img">
    </div>
</body>
</html>