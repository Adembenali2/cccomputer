<?php
// source/connexion/login_process.php
require_once __DIR__ . '/../../includes/session_config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/historique.php';
require_once __DIR__ . '/../../includes/rate_limiter.php';

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

// Protection brute-force : limite les tentatives par IP (checkRateLimit incrémente si la limite n’est pas encore atteinte)
$loginRateKey = 'login_attempt_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!checkRateLimit($loginRateKey, 5, 600)) {
    $_SESSION['login_error'] = 'Trop de tentatives. Réessayez dans 10 minutes.';
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
        last_activity,
        last_login_at
    FROM utilisateurs 
    WHERE Email = :email 
    LIMIT 1
");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Vérifs
if (!$user || !password_verify($pass, $user['password'])) {
    // Protection brute-force : échec = cette requête a déjà été comptée par checkRateLimit ci-dessus
    enregistrerAction($pdo, null, 'connexion_echouee', 'Tentative échouée');
    $_SESSION['login_error'] = "Adresse e-mail ou mot de passe incorrect.";
    header('Location: /public/login.php');
    exit;
}

if (($user['statut'] ?? 'inactif') !== 'actif') {
    enregistrerAction($pdo, (int)$user['id'], 'connexion_echouee', 'Compte désactivé');
    $_SESSION['login_error'] = "Votre compte est désactivé.";
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

// [Fonctionnalité C] Ancienne date de dernière connexion (affichage) puis mise à jour en base
$_SESSION['last_login_at'] = $user['last_login_at'] ?? null;
try {
    $stmtLogin = $pdo->prepare('UPDATE utilisateurs SET last_login_at = NOW() WHERE id = ?');
    $stmtLogin->execute([(int)$user['id']]);
} catch (PDOException $e) {
    error_log('[Fonctionnalité C] last_login_at update: ' . $e->getMessage());
}

// [Fonctionnalité D] Enregistrer la session active (table optionnelle jusqu’à migration)
try {
    $stmtSess = $pdo->prepare('
        INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE last_activity = NOW()
    ');
    $stmtSess->execute([
        (int)$user['id'],
        session_id(),
        $_SERVER['REMOTE_ADDR'] ?? '',
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (PDOException $e) {
    /* table pas encore créée */
}

$_SESSION['user_id']     = (int)$user['id'];
$_SESSION['user_email']  = $user['Email'];
$_SESSION['user_nom']    = $user['nom'];
$_SESSION['user_prenom'] = $user['prenom'];
$_SESSION['emploi']      = $user['Emploi'];
$_SESSION['csrf_token']  = bin2hex(random_bytes(32));
$_SESSION['last_regenerate'] = time();
$_SESSION['last_activity_update'] = time();
$_SESSION['last_db_activity_sync'] = time();

// Mise à jour de last_activity lors de la connexion
try {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET last_activity = NOW() WHERE id = ?");
    $stmt->execute([(int)$user['id']]);
} catch (PDOException $e) {
    // Si le champ n'existe pas encore, on ignore l'erreur (migration pas encore appliquée)
    error_log('Warning: last_activity update on login failed (field may not exist): ' . $e->getMessage());
}

enregistrerAction($pdo, (int)$user['id'], 'connexion_reussie', 'Connexion réussie');

// Protection brute-force : réinitialiser le compteur (même clé / stockage que rate_limiter.php)
$rlKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $loginRateKey);
$rlCacheKey = 'ratelimit_' . $rlKey;
if (function_exists('apcu_delete')) {
    @apcu_delete($rlCacheKey);
}
$rlCacheDir = __DIR__ . '/../../cache/ratelimit';
$rlCacheFile = $rlCacheDir . '/' . md5($rlKey) . '.json';
if (is_file($rlCacheFile)) {
    @unlink($rlCacheFile);
}

// Redirection directe vers le dashboard
header('Location: /public/dashboard.php');
exit;
