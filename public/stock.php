<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenue - GestionDeParc</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/style.css"> 
    
    <style>
        .splash-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 1rem;
        }

        .splash-card {
            text-align: center;
            max-width: 500px;
            width: 100%;
        }

        /* Le style du logo ne gère plus la couleur */
        .animated-logo {
            text-decoration: none;
            display: inline-block;
            transition: transform 0.3s ease-in-out;
            animation: pulse-animation 3s infinite cubic-bezier(0.45, 0, 0.55, 1);
        }

        .animated-logo:hover {
            transform: scale(1.08); 
        }
        
        @keyframes pulse-animation {
            0%   { transform: scale(1); }
            50%  { transform: scale(1.03); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>

    <div class="splash-container">
        <div class="card splash-card p-4 p-md-5">

            <a href="/stcok/dashboard_stock.php" class="navbar-brand animated-logo">
                
                <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" fill="currentColor" class="bi bi-box-seam-fill" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M15.528 2.973a.75.75 0 0 1 .472.696v8.662a.75.75 0 0 1-.472.696l-7.25 2.9a.75.75 0 0 1-.557 0l-7.25-2.9A.75.75 0 0 1 0 12.331V3.669a.75.75 0 0 1 .471-.696L7.721.023a.75.75 0 0 1 .557 0l7.25 2.9zM8 8.5l-5 2v2.669l5 2V8.5zM8 1l-5 2v3.5l5 2v-7zM15 5.5l-5-2v7l5-2V5.5z"/>
                </svg>
                
                <h1 class="display-5 mt-3">GestionDeParc</h1>
                
                <p class="lead" style="color: var(--text-light);">
                    Cliquez pour vous connecter
                </p>
            </a>

        </div>
    </div>

</body>
</html>