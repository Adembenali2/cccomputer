<?php
// /source/connexion/login_process.php (VERSION SÉCURISÉE)

// 1️⃣ Détruire toute session existante de manière propre
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

// 2️⃣ Démarrer une nouvelle session propre
require_once __DIR__ . '/../../includes/session_config.php';
session_regenerate_id(true);

// 3️⃣ Charger dépendances
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/historique.php';

// 4️⃣ Récupérer email et mot de passe
$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
    $_SESSION['login_error'] = "Veuillez remplir tous les champs.";
    header('Location: ../../public/login.php');
    exit;
}

// 5️⃣ Vérifier utilisateur
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE Email = :email LIMIT 1");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($pass, $user['password'])) {
    $_SESSION['login_error'] = "Adresse e-mail ou mot de passe incorrect.";
    $uid = $user ? (int)$user['id'] : 0;
    enregistrerAction($pdo, $uid, 'connexion_refusee_identifiants', 'Tentative de connexion échouée');
    header('Location: ../../redirection/erreur_connexion.php');
    exit;
}

if (($user['statut'] ?? 'inactif') !== 'actif') {
    $_SESSION['login_error'] = "Votre compte est désactivé.";
    enregistrerAction($pdo, $user['id'], 'connexion_refusee_compte_inactif', 'Compte désactivé');
    header('Location: ../../redirection/compte_desactiver.php');
    exit;
}

// 6️⃣ Rehash mot de passe si nécessaire
if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 10])) {
    $newHash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
    $upd = $pdo->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?");
    $upd->execute([$newHash, $user['id']]);
}

// 7️⃣ Remplir la session avec les infos de l'utilisateur
$_SESSION = [
    'user_id'     => (int)$user['id'],
    'user_email'  => $user['Email'],
    'user_nom'    => $user['nom'],
    'user_prenom' => $user['prenom'],
    'emploi'      => $user['Emploi'],
];

// 8️⃣ CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// 9️⃣ Historique
enregistrerAction($pdo, $user['id'], 'connexion_reussie', 'Connexion réussie');

// 10️⃣ Redirection
header('Location: ../../redirection/valide_connexion.php');
exit;
?>
