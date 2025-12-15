<?php
// source/connexion/login_process.php
require_once __DIR__ . '/../../includes/session_config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/historique.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

// CSRF (optionnel mais conseillé)
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['login_error'] = "Session invalide. Recommencez.";
    header('Location: /public/login.php');
    exit;
}

// Entrées
$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
    $_SESSION['login_error'] = "Veuillez remplir tous les champs.";
    header('Location: /public/login.php');
    exit;
}

// Récup utilisateur - sélection explicite selon le schéma railway.sql
$stmt = $pdo->prepare("
    SELECT 
        id,
        Email,
        password,
        nom,
        prenom,
        telephone,
        Emploi,
        statut,
        date_debut,
        date_creation,
        date_modification,
        last_activity
    FROM utilisateurs 
    WHERE Email = :email 
    LIMIT 1
");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Vérifs
if (!$user || !password_verify($pass, $user['password'])) {
    $_SESSION['login_error'] = "Adresse e-mail ou mot de passe incorrect.";
    // Note: Les connexions/déconnexions ne sont plus enregistrées dans l'historique
    header('Location: /public/login.php');
    exit;
}

if (($user['statut'] ?? 'inactif') !== 'actif') {
    $_SESSION['login_error'] = "Votre compte est désactivé.";
    // Note: Les connexions/déconnexions ne sont plus enregistrées dans l'historique
    header('Location: /public/login.php');
    exit;
}

// Rehash si nécessaire
if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 10])) {
    $newHash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
    $upd = $pdo->prepare("UPDATE utilisateurs SET password = :password WHERE id = :id");
    $upd->execute([':password' => $newHash, ':id' => $user['id']]);
}

// Écritures session
session_regenerate_id(true);
$_SESSION['user_id']     = (int)$user['id'];
$_SESSION['user_email']  = $user['Email'];
$_SESSION['user_nom']    = $user['nom'];
$_SESSION['user_prenom'] = $user['prenom'];
$_SESSION['emploi']      = $user['Emploi'];
$_SESSION['csrf_token']  = bin2hex(random_bytes(32));
$_SESSION['last_regenerate'] = time();
$_SESSION['last_activity_update'] = time();

// Mise à jour de last_activity lors de la connexion
try {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET last_activity = NOW() WHERE id = ?");
    $stmt->execute([(int)$user['id']]);
} catch (PDOException $e) {
    // Si le champ n'existe pas encore, on ignore l'erreur (migration pas encore appliquée)
    error_log('Warning: last_activity update on login failed (field may not exist): ' . $e->getMessage());
}

// Note: Les connexions/déconnexions ne sont plus enregistrées dans l'historique

// Redirection directe vers le dashboard
header('Location: /public/dashboard.php');
exit;
