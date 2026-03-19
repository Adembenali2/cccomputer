<?php
declare(strict_types=1);

/**
 * Gestion des paramètres applicatifs (table parametres_app)
 * Priorité : DB > variables d'environnement > défaut
 */

/** Définition des paramètres toggleables (cle => [label, description, défaut]) */
const PARAMETRES_DEF = [
    'auto_send_emails' => [
        'label' => 'Envoi automatique des emails',
        'desc' => 'Reçus de paiement et factures envoyés automatiquement lors de l\'enregistrement ou validation d\'un paiement.',
        'default' => '0',
        'category' => 'emails',
    ],
    'module_dashboard' => ['label' => 'Dashboard', 'desc' => 'Tableau de bord et accueil', 'default' => '1', 'category' => 'modules'],
    'module_agenda' => ['label' => 'Agenda', 'desc' => 'Planning et calendrier', 'default' => '1', 'category' => 'modules'],
    'module_historique' => ['label' => 'Historique', 'desc' => 'Historique des actions utilisateurs', 'default' => '1', 'category' => 'modules'],
    'module_clients' => ['label' => 'Clients', 'desc' => 'Gestion des clients et fiches clients', 'default' => '1', 'category' => 'modules'],
    'module_paiements' => ['label' => 'Paiements & Factures', 'desc' => 'Gestion des paiements et facturation', 'default' => '1', 'category' => 'modules'],
    'module_messagerie' => ['label' => 'Messagerie', 'desc' => 'Messagerie interne et chatroom', 'default' => '1', 'category' => 'modules'],
    'module_sav' => ['label' => 'SAV', 'desc' => 'Gestion du service après-vente', 'default' => '1', 'category' => 'modules'],
    'module_livraison' => ['label' => 'Livraisons', 'desc' => 'Gestion des livraisons', 'default' => '1', 'category' => 'modules'],
    'module_stock' => ['label' => 'Stock', 'desc' => 'Gestion du stock (papier, toner, etc.)', 'default' => '1', 'category' => 'modules'],
    'module_photocopieurs' => ['label' => 'Photocopieurs', 'desc' => 'Détails des photocopieurs et relevés', 'default' => '1', 'category' => 'modules'],
    'module_maps' => ['label' => 'Cartes', 'desc' => 'Cartes et planification des itinéraires', 'default' => '1', 'category' => 'modules'],
    'module_profil' => ['label' => 'Gestion Utilisateurs', 'desc' => 'Profil et gestion des utilisateurs', 'default' => '1', 'category' => 'modules'],
    'module_commercial' => ['label' => 'Espace commercial', 'desc' => 'Espace commercial (Chargé relation clients)', 'default' => '1', 'category' => 'modules'],
    'module_import_sftp' => ['label' => 'Import SFTP', 'desc' => 'Import automatique des relevés via SFTP', 'default' => '1', 'category' => 'imports'],
    'module_import_ionos' => ['label' => 'Import IONOS', 'desc' => 'Import automatique des relevés via IONOS', 'default' => '1', 'category' => 'imports'],
];

/** Mapping page -> clé paramètre (pour authorize_page) */
const PAGE_TO_PARAM = [
    'dashboard' => 'module_dashboard',
    'agenda' => 'module_agenda',
    'historique' => 'module_historique',
    'clients' => 'module_clients',
    'client_fiche' => 'module_clients',
    'paiements' => 'module_paiements',
    'messagerie' => 'module_messagerie',
    'sav' => 'module_sav',
    'livraison' => 'module_livraison',
    'stock' => 'module_stock',
    'photocopieurs_details' => 'module_photocopieurs',
    'maps' => 'module_maps',
    'profil' => 'module_profil',
    'commercial' => 'module_commercial',
];

/**
 * Récupère la valeur d'un paramètre (booléen)
 */
function getParametre(PDO $pdo, string $cle): bool
{
    $def = PARAMETRES_DEF[$cle] ?? null;
    $default = $def['default'] ?? '0';
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM parametres_app WHERE cle = ? LIMIT 1");
        $stmt->execute([$cle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && isset($row['valeur'])) {
            return filter_var($row['valeur'], FILTER_VALIDATE_BOOLEAN);
        }
    } catch (PDOException $e) {
        // Table peut ne pas exister
    }
    return filter_var($default, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Définit la valeur d'un paramètre
 */
function setParametre(PDO $pdo, string $cle, bool $valeur): void
{
    $stmt = $pdo->prepare("
        INSERT INTO parametres_app (cle, valeur, updated_at) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), updated_at = NOW()
    ");
    $stmt->execute([$cle, $valeur ? '1' : '0']);
}

/**
 * Récupère tous les paramètres
 */
function getAllParametres(PDO $pdo): array
{
    $result = [];
    foreach (PARAMETRES_DEF as $cle => $def) {
        $result[$cle] = [
            'enabled' => getParametre($pdo, $cle),
            'label' => $def['label'],
            'desc' => $def['desc'],
            'category' => $def['category'],
        ];
    }
    return $result;
}

/**
 * Vérifie si un module est activé (pour une page)
 */
function isModuleEnabled(PDO $pdo, string $page): bool
{
    $param = PAGE_TO_PARAM[$page] ?? null;
    if ($param === null) {
        return true; // Page inconnue = autorisée
    }
    return getParametre($pdo, $param);
}

/**
 * @deprecated Utiliser getParametre($pdo, 'auto_send_emails')
 */
function getAutoSendEmailsEnabled(PDO $pdo): bool
{
    return getParametre($pdo, 'auto_send_emails');
}

/**
 * @deprecated Utiliser setParametre($pdo, 'auto_send_emails', $enabled)
 */
function setAutoSendEmailsEnabled(PDO $pdo, bool $enabled): void
{
    setParametre($pdo, 'auto_send_emails', $enabled);
}
