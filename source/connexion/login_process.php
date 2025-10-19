<?php
// source/connexion/login_process.php
require_once __DIR__ . '/../../includes/session_config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/historique.php';

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

// Récup utilisateur
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE Email = :email LIMIT 1");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Vérifs
if (!$user || !password_verify($pass, $user['password'])) {
    $_SESSION['login_error'] = "Adresse e-mail ou mot de passe incorrect.";
    enregistrerAction($pdo, $user ? (int)$user['id'] : 0, 'connexion_refusee_identifiants', 'Tentative échouée');
    header('Location: /public/login.php');
    exit;
}

if (($user['statut'] ?? 'inactif') !== 'actif') {
    $_SESSION['login_error'] = "Votre compte est désactivé.";
    enregistrerAction($pdo, (int)$user['id'], 'connexion_refusee_compte_inactif', 'Compte désactivé');
    header('Location: /public/login.php');
    exit;
}

// Rehash si nécessaire
if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 10])) {
    $newHash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
    $upd = $pdo->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?");
    $upd->execute([$newHash, $user['id']]);
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

enregistrerAction($pdo, (int)$user['id'], 'connexion_reussie', 'Connexion réussie');

// Redirection directe vers le dashboard
header('Location: /public/dashboard.php');
exit;
