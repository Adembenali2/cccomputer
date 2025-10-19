<?php
/**
 * ===================================================================
 * FICHIER DE DÉBOGAGE : index.php
 * ===================================================================
 * OBJECTIF : Trouver où le code s'arrête.
 * COMMENT FAIRE :
 * 1. Décommentez UNE SEULE ligne `die(...)` à la fois, en commençant par la première.
 * 2. Déployez votre code.
 * 3. Si vous voyez le message du "Point de contrôle", c'est que tout va bien jusqu'ici.
 * 4. Commentez la ligne que vous aviez décommentée, et décommentez la suivante.
 * 5. Répétez jusqu'à ce que vous obteniez l'erreur "Application failed to respond".
 * L'erreur se situe juste après le dernier point de contrôle qui a fonctionné.
 */

// --- DÉBUT DU BLOC DE DÉBOGAGE ---
// Force l'affichage de toutes les erreurs PHP. Essentiel sur un serveur de production.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- FIN DU BLOC DE DÉBOGAGE ---


// Décommentez la ligne ci-dessous pour le premier test.
die("Point de contrôle 1 : Le script démarre et les erreurs sont activées.");


// Démarre la session. C'est une cause fréquente d'erreur si les permissions de dossier sont mauvaises.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// die("Point de contrôle 2 : La session a été démarrée avec session_start().");


// Vérifie si la variable de session existe pour déterminer la redirection.
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    
    // die("Point de contrôle 3 : L'utilisateur est considéré comme connecté.");
    $redirectUrl = 'dashboard.php'; // Chemin corrigé (sans 'public/')

} else {
    
    // die("Point de contrôle 4 : L'utilisateur est considéré comme NON connecté.");
    $redirectUrl = 'login.php'; // Chemin corrigé (sans 'public/')

}

// Si vous arrivez jusqu'ici, le PHP a fonctionné. Le problème pourrait être le HTML/JS.
// die("Point de contrôle 5 : La logique PHP est terminée, le HTML va être généré.");

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bienvenue | CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Votre CSS */
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
        // Le script de redirection s'exécutera après le chargement de la page.
        setTimeout(function() {
            // La redirection utilise la variable PHP préparée plus haut.
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