<?php
// /api/stock_add.php
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/Validator.php';

initApi();
requireApiAuth();

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

require_once __DIR__ . '/../includes/historique.php';

/**
 * Valide une valeur : soit dans la liste autorisée, soit valeur custom (non vide, maxLen).
 */
function validateField(string $value, array $allowed, string $fieldName, bool $allowCustom = true, int $maxLen = 100): string {
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException("Le champ {$fieldName} est obligatoire.");
    }
    if (in_array($value, $allowed, true)) {
        return $value;
    }
    if ($allowCustom && strlen($value) <= $maxLen) {
        return $value;
    }
    $allowedStr = implode(', ', array_filter($allowed, fn($v) => $v !== '' && $v !== 'Autre'));
    throw new RuntimeException("Valeur invalide pour {$fieldName}. Valeurs attendues : {$allowedStr} ou une valeur personnalisée.");
}

// Fonction helper pour enregistrer dans l'historique
function logStockAction(PDO $pdo, string $action, string $details): void {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        enregistrerAction($pdo, $userId, $action, $details);
    } catch (Throwable $e) {
        error_log('stock_add.php log error: ' . $e->getMessage());
    }
}

/**
 * Génère un QR Code pour un produit et le sauvegarde
 * Contenu : URL interne pour lookup (get_product_by_barcode?barcode=XXX) ou fallback TYPE;ID;BARCODE
 * Dossier : /uploads/qrcodes/<type>/<id>.png (nom safe, id numérique uniquement)
 *
 * @param string $barcode Code-barres du produit
 * @param int $productId ID du produit
 * @param string $type Type de produit (papier, toner, lcd, pc)
 * @return string|null Chemin vers l'image QR Code ou null en cas d'erreur
 */
function generateQRCode(string $barcode, int $productId, string $type): ?string {
    $allowedTypes = ['papier', 'toner', 'lcd', 'pc'];
    if (!in_array($type, $allowedTypes, true) || $productId <= 0) {
        return null;
    }

    if ($barcode !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . '://' . $host;
        $qrData = $baseUrl . '/API/get_product_by_barcode.php?barcode=' . urlencode($barcode);
    } else {
        $qrData = sprintf('TYPE:%s;ID:%d', $type, $productId);
    }
    $baseDir = dirname(__DIR__);
    $qrDir = $baseDir . '/uploads/qrcodes/' . $type;
    $filename = (string) $productId . '.png';
    $filepath = $qrDir . '/' . $filename;

    try {
        if (!is_dir($qrDir)) {
            if (!@mkdir($qrDir, 0755, true)) {
                error_log('Erreur génération QR Code: impossible de créer le dossier ' . $qrDir);
                return null;
            }
        }

        if (!is_writable($qrDir)) {
            error_log('Erreur génération QR Code: dossier non accessible en écriture ' . $qrDir);
            return null;
        }

        $qrImage = null;

        if (class_exists(\Endroid\QrCode\Builder\Builder::class)) {
            try {
                $builder = new \Endroid\QrCode\Builder\Builder(
                    writer: new \Endroid\QrCode\Writer\PngWriter(),
                    writerOptions: [],
                    data: $qrData,
                    size: 300,
                    margin: 10
                );
                $result = $builder->build();
                $qrImage = $result->getString();
            } catch (Throwable $e) {
                error_log('Erreur endroid/qr-code: ' . $e->getMessage());
            }
        }

        if ($qrImage === null || $qrImage === '') {
            $qrSize = 300;
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qrSize . 'x' . $qrSize . '&data=' . urlencode($qrData);
            $ctx = stream_context_create([
                'http' => ['timeout' => 10],
                'ssl' => ['verify_peer' => true]
            ]);
            $qrImage = @file_get_contents($qrUrl, false, $ctx);
        }

        if ($qrImage === false || $qrImage === '' || $qrImage === null) {
            error_log('Erreur génération QR Code: impossible de générer l\'image');
            return null;
        }

        if (@file_put_contents($filepath, $qrImage) === false) {
            error_log('Erreur génération QR Code: impossible d\'écrire le fichier ' . $filepath);
            return null;
        }

        return '/uploads/qrcodes/' . $type . '/' . $filename;
    } catch (Throwable $e) {
        error_log('Erreur génération QR Code: ' . $e->getMessage());
        return null;
    }
}

/**
 * Génère un code-barres unique pour un produit
 * Format: TYPE-YYYYMMDD-HHMMSS-XXXX (ex: PAP-20241201-143022-0001)
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param string $type Type de produit (papier, toner, lcd, pc)
 * @param string $table Nom de la table (paper_catalog, toner_catalog, etc.)
 * @return string Code-barres unique
 */
function generateBarcode(PDO $pdo, string $type, string $table): string {
    // Validation des noms de tables autorisés (sécurité)
    $allowedTables = ['paper_catalog', 'toner_catalog', 'lcd_catalog', 'pc_catalog'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Table non autorisée: ' . $table);
    }
    
    $prefix = strtoupper(substr($type, 0, 3));
    $date = date('Ymd');
    $time = date('His');
    
    // Générer un numéro séquentiel unique pour cette seconde
    // Utilisation d'un switch pour éviter l'interpolation de variable dans SQL
    $sql = '';
    switch ($table) {
        case 'paper_catalog':
            $sql = "SELECT COUNT(*) FROM paper_catalog WHERE barcode LIKE :pattern";
            break;
        case 'toner_catalog':
            $sql = "SELECT COUNT(*) FROM toner_catalog WHERE barcode LIKE :pattern";
            break;
        case 'lcd_catalog':
            $sql = "SELECT COUNT(*) FROM lcd_catalog WHERE barcode LIKE :pattern";
            break;
        case 'pc_catalog':
            $sql = "SELECT COUNT(*) FROM pc_catalog WHERE barcode LIKE :pattern";
            break;
        default:
            throw new InvalidArgumentException('Table non autorisée: ' . $table);
    }
    
    $pattern = "{$prefix}-{$date}-{$time}-%";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pattern' => $pattern]);
    $count = (int)$stmt->fetchColumn();
    
    $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    $barcode = "{$prefix}-{$date}-{$time}-{$sequence}";
    
    // Vérifier l'unicité (double vérification)
    switch ($table) {
        case 'paper_catalog':
            $sql = "SELECT COUNT(*) FROM paper_catalog WHERE barcode = :barcode";
            break;
        case 'toner_catalog':
            $sql = "SELECT COUNT(*) FROM toner_catalog WHERE barcode = :barcode";
            break;
        case 'lcd_catalog':
            $sql = "SELECT COUNT(*) FROM lcd_catalog WHERE barcode = :barcode";
            break;
        case 'pc_catalog':
            $sql = "SELECT COUNT(*) FROM pc_catalog WHERE barcode = :barcode";
            break;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':barcode' => $barcode]);
    if ($stmt->fetchColumn() > 0) {
        // Si collision, ajouter un suffixe aléatoire
        $barcode = "{$prefix}-{$date}-{$time}-{$sequence}-" . substr(md5(uniqid()), 0, 4);
    }
    
    return $barcode;
}

/**
 * [Nouveau module stock] Génère une référence stock unique par catégorie.
 */
function generateStockReference(PDO $pdo, string $categorie): string
{
    $now = new DateTimeImmutable('now');
    $ymd = $now->format('Ymd');
    $hms = $now->format('His');
    $prefixMap = [
        'papier' => "PAP-{$ymd}-{$hms}",
        'toner_noir' => "TON-N-{$ymd}",
        'toner_cyan' => "TON-C-{$ymd}",
        'toner_magenta' => "TON-M-{$ymd}",
        'toner_jaune' => "TON-J-{$ymd}",
        'pc' => "PC-{$ymd}",
        'ecran_lcd' => "LCD-{$ymd}",
        'imprimante' => "IMP-{$ymd}",
        'piece_detachee' => "PDR-{$ymd}",
        'consommable' => "CON-{$ymd}",
        'autre' => "ART-{$ymd}",
    ];
    $base = $prefixMap[$categorie] ?? "ART-{$ymd}";
    $pattern = $base . '-%';

    $stmt = $pdo->prepare("SELECT reference FROM stock WHERE reference LIKE :p ORDER BY reference DESC LIMIT 1");
    $stmt->execute([':p' => $pattern]);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = 1;
    if ($last !== '' && preg_match('/-(\d{4})$/', $last, $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return $base . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

// Vérifier l'authentification sans redirection HTML
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Générer un token CSRF si manquant
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Vérifier que la connexion existe
if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Connexion base de données manquante'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}
if (!in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant', 'Secrétaire'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Accès refusé'], 403);
}

// Lire et décoder le JSON une seule fois
$raw = file_get_contents('php://input');
$jsonData = json_decode($raw, true);

// Vérification CSRF
$csrfToken = $jsonData['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

// Utiliser les données décodées
$data = $jsonData ?? [];
if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'JSON invalide'], 400);
}

// --------------------------------------------------------------------
// [Nouveau module stock] Mode insertion table `stock`
// Accepte payload racine OU payload dans `data`
// --------------------------------------------------------------------
$candidate = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
$newStockCategories = ['papier','toner_noir','toner_cyan','toner_magenta','toner_jaune','pc','ecran_lcd','imprimante','piece_detachee','consommable','autre'];
if (isset($candidate['categorie']) && in_array((string)$candidate['categorie'], $newStockCategories, true)) {
    $categorie = (string)$candidate['categorie'];
    $designation = trim((string)($candidate['designation'] ?? ''));
    $reference = trim((string)($candidate['reference'] ?? ''));
    $marque = trim((string)($candidate['marque'] ?? ''));
    $modeleCompatible = trim((string)($candidate['modele_compatible'] ?? ''));
    $quantite = max(0, (int)($candidate['quantite'] ?? 0));
    $quantiteMin = max(0, (int)($candidate['quantite_min'] ?? 5));
    $prixUnitaire = max(0.0, (float)($candidate['prix_unitaire_ht'] ?? 0));
    $emplacement = trim((string)($candidate['emplacement'] ?? ''));
    $actif = (int)($candidate['actif'] ?? 1) === 1 ? 1 : 0;
    $unite = trim((string)($candidate['unite'] ?? 'unite'));
    $contenance = isset($candidate['contenance']) && $candidate['contenance'] !== '' ? (int)$candidate['contenance'] : null;

    if ($designation === '') {
        jsonResponse(['ok' => false, 'error' => 'designation obligatoire'], 400);
    }
    if (!in_array($unite, ['unite', 'carton', 'rame'], true)) {
        $unite = 'unite';
    }
    if ($categorie === 'papier') {
        if ($unite === 'unite') {
            $unite = 'carton';
        }
        if ($contenance === null || $contenance <= 0) {
            $contenance = 2500;
        }
    }

    try {
        $pdo->beginTransaction();
        if ($reference === '') {
            $reference = generateStockReference($pdo, $categorie);
        }

        $stmt = $pdo->prepare("
            INSERT INTO stock
            (reference, designation, categorie, marque, modele_compatible, quantite, quantite_min, prix_unitaire_ht, emplacement, actif, unite, contenance)
            VALUES
            (:reference, :designation, :categorie, :marque, :modele_compatible, :quantite, :quantite_min, :prix_unitaire_ht, :emplacement, :actif, :unite, :contenance)
        ");
        $stmt->execute([
            ':reference' => $reference,
            ':designation' => $designation,
            ':categorie' => $categorie,
            ':marque' => $marque !== '' ? $marque : null,
            ':modele_compatible' => $modeleCompatible !== '' ? $modeleCompatible : null,
            ':quantite' => $quantite,
            ':quantite_min' => $quantiteMin,
            ':prix_unitaire_ht' => $prixUnitaire,
            ':emplacement' => $emplacement !== '' ? $emplacement : null,
            ':actif' => $actif,
            ':unite' => $unite,
            ':contenance' => $contenance,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if ($quantite > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO stock_mouvements (stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc, created_by)
                VALUES (:stock_id, 'entree', :quantite, 0, :quantite_apres, 'creation_article', :reference_doc, :created_by)
            ");
            $stmt->execute([
                ':stock_id' => $newId,
                ':quantite' => $quantite,
                ':quantite_apres' => $quantite,
                ':reference_doc' => $reference,
                ':created_by' => $_SESSION['user_id'] ?? null,
            ]);
        }

        $pdo->commit();
        logStockAction($pdo, 'stock_article_cree', "Article stock #{$newId} {$reference} ({$categorie})");
        jsonResponse(['ok' => true, 'id' => $newId, 'reference' => $reference], 200);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000' && $reference !== '') {
            jsonResponse(['ok' => false, 'error' => 'Référence déjà existante'], 409);
        }
        throw $e;
    }
}

try {
    $type = Validator::enum($data['type'] ?? '', ['papier', 'toner', 'lcd', 'pc']);
    $payload = $data['data'] ?? [];
    if (!is_array($payload)) {
        jsonResponse(['ok' => false, 'error' => 'JSON invalide'], 400);
    }
    Validator::requireFields(['qty_delta'], $payload);
    $qtyDeltaValidated = Validator::int($payload['qty_delta']);
    if ($qtyDeltaValidated === 0) {
        throw new InvalidArgumentException('qty_delta doit être différent de 0');
    }
    if (array_key_exists('reason', $payload)) {
        $reasonRaw = trim((string) $payload['reason']);
        if ($reasonRaw === '') {
            apiFail('Champ requis manquant : reason', 400);
        }
        Validator::string($payload['reason'], 100);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
}

$apiResponse = ['ok' => true];

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($type) {

        /* ===================== PAPIER ===================== */
        case 'papier':
            $marque = trim($payload['marque'] ?? '');
            $modele = trim($payload['modele'] ?? '');
            $poids  = validateField($payload['poids'] ?? '', ['70', '80', '90', '100'], 'poids', true, 20);
            $qty    = (int)($payload['qty_delta'] ?? 0);
            $ref    = trim($payload['reference'] ?? '');

            if ($marque === '' || $modele === '' || $qty <= 0) {
                throw new RuntimeException('Champs obligatoires manquants (marque, modèle, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer le papier dans paper_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM paper_catalog
                WHERE marque = :marque AND modele = :modele AND poids = :poids
                LIMIT 1
            ");
            $stmt->execute([
                ':marque' => $marque,
                ':modele' => $modele,
                ':poids'  => $poids,
            ]);
            $paperId = $stmt->fetchColumn();
            $qrPath = null;

            if (!$paperId) {
                // Générer un code-barres unique
                $barcode = generateBarcode($pdo, 'papier', 'paper_catalog');
                
                $stmt = $pdo->prepare("
                    INSERT INTO paper_catalog (marque, modele, poids, barcode)
                    VALUES (:marque, :modele, :poids, :barcode)
                ");
                $stmt->execute([
                    ':marque' => $marque,
                    ':modele' => $modele,
                    ':poids'  => $poids,
                    ':barcode' => $barcode,
                ]);
                $paperId = $pdo->lastInsertId();
                
                // Générer et sauvegarder le QR Code
                $qrPath = generateQRCode($barcode, $paperId, 'papier');
                if ($qrPath) {
                    $stmt = $pdo->prepare("UPDATE paper_catalog SET qr_code_path = :qr_path WHERE id = :id");
                    $stmt->execute([':qr_path' => $qrPath, ':id' => $paperId]);
                } else {
                    $apiResponse['warning'] = 'Impossible de générer le QR code. L\'étiquette utilisera le code-barres.';
                }
            }

            $apiResponse['id'] = (int) $paperId;
            if ($qrPath !== null) {
                $apiResponse['qr_code_path'] = $qrPath;
            }

            // 2) insérer le mouvement
            $stmt = $pdo->prepare("
                INSERT INTO paper_moves (paper_id, qty_delta, reason, reference, user_id)
                VALUES (:paper_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':paper_id'  => $paperId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            
            // Enregistrer dans l'historique
            $details = sprintf('Ajout papier: %s %s - Poids: %s - Quantité: %d', $marque, $modele, $poids, $qty);
            if ($ref !== '') {
                $details .= ' - Référence: ' . $ref;
            }
            logStockAction($pdo, 'ajout_stock_papier', $details);
            break;

        /* ===================== TONER ===================== */
        case 'toner':
            $marque  = trim($payload['marque']  ?? '');
            $modele  = trim($payload['modele']  ?? '');
            $couleur = validateField($payload['couleur'] ?? '', ['Noir', 'Cyan', 'Magenta', 'Jaune'], 'couleur', true, 50);
            $qty     = (int)($payload['qty_delta'] ?? 0);
            $ref     = trim($payload['reference'] ?? '');

            if ($marque === '' || $modele === '' || $qty <= 0) {
                throw new RuntimeException('Champs obligatoires manquants (marque, modèle, couleur, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer dans toner_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM toner_catalog
                WHERE marque = :marque AND modele = :modele AND couleur = :couleur
                LIMIT 1
            ");
            $stmt->execute([
                ':marque'  => $marque,
                ':modele'  => $modele,
                ':couleur' => $couleur,
            ]);
            $tonerId = $stmt->fetchColumn();
            $qrPath = null;

            if (!$tonerId) {
                // Générer un code-barres unique
                $barcode = generateBarcode($pdo, 'toner', 'toner_catalog');
                
                $stmt = $pdo->prepare("
                    INSERT INTO toner_catalog (marque, modele, couleur, barcode)
                    VALUES (:marque, :modele, :couleur, :barcode)
                ");
                $stmt->execute([
                    ':marque'  => $marque,
                    ':modele'  => $modele,
                    ':couleur' => $couleur,
                    ':barcode' => $barcode,
                ]);
                $tonerId = $pdo->lastInsertId();
                
                // Générer et sauvegarder le QR Code
                $qrPath = generateQRCode($barcode, $tonerId, 'toner');
                if ($qrPath) {
                    $stmt = $pdo->prepare("UPDATE toner_catalog SET qr_code_path = :qr_path WHERE id = :id");
                    $stmt->execute([':qr_path' => $qrPath, ':id' => $tonerId]);
                } else {
                    $apiResponse['warning'] = 'Impossible de générer le QR code. L\'étiquette utilisera le code-barres.';
                }
            }

            $apiResponse['id'] = (int) $tonerId;
            if ($qrPath !== null) {
                $apiResponse['qr_code_path'] = $qrPath;
            }

            // 2) mouvement
            $stmt = $pdo->prepare("
                INSERT INTO toner_moves (toner_id, qty_delta, reason, reference, user_id)
                VALUES (:toner_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':toner_id'  => $tonerId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            
            // Enregistrer dans l'historique
            $details = sprintf('Ajout toner: %s %s - Couleur: %s - Quantité: %d', $marque, $modele, $couleur, $qty);
            if ($ref !== '') {
                $details .= ' - Référence: ' . $ref;
            }
            logStockAction($pdo, 'ajout_stock_toner', $details);
            break;

        /* ===================== LCD ===================== */
        case 'lcd':
            $marque     = trim($payload['marque']     ?? '');
            $reference  = trim($payload['reference']  ?? '');
            $etat       = validateField(strtoupper(trim($payload['etat'] ?? '')), ['A', 'B', 'C'], 'état', false);
            $modele     = trim($payload['modele']     ?? '');
            $taille     = (int)($payload['taille']    ?? 0);
            $resolution = validateField($payload['resolution'] ?? '', ['1920x1080', '2560x1440', '3840x2160', '1366x768', '1680x1050'], 'résolution', true, 20);
            $connectique= validateField($payload['connectique'] ?? '', ['HDMI', 'DisplayPort', 'VGA', 'DVI', 'USB-C', 'HDMI+VGA'], 'connectique', true, 100);
            $prix       = $payload['prix'] === '' ? null : (float)$payload['prix'];
            $qty        = (int)($payload['qty_delta'] ?? 0);
            $refMove    = trim($payload['reference_move'] ?? '');
            $ref        = $refMove !== '' ? $refMove : $reference;

            if ($marque === '' || $reference === '' || $modele === '' || $taille < 10 || $qty <= 0) {
                throw new RuntimeException('Champs obligatoires manquants (marque, réf., modèle, taille ≥ 10, résolution, connectique, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer dans lcd_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM lcd_catalog
                WHERE marque = :marque AND reference = :reference
                LIMIT 1
            ");
            $stmt->execute([
                ':marque'    => $marque,
                ':reference' => $reference,
            ]);
            $lcdId = $stmt->fetchColumn();
            $qrPath = null;

            if ($lcdId) {
                // mise à jour des métadonnées
                $stmt = $pdo->prepare("
                    UPDATE lcd_catalog
                    SET etat = :etat,
                        modele = :modele,
                        taille = :taille,
                        resolution = :resolution,
                        connectique = :connectique,
                        prix = :prix
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':etat'       => $etat,
                    ':modele'     => $modele,
                    ':taille'     => $taille,
                    ':resolution' => $resolution,
                    ':connectique'=> $connectique,
                    ':prix'       => $prix,
                    ':id'         => $lcdId,
                ]);
            } else {
                // Générer un code-barres unique
                $barcode = generateBarcode($pdo, 'lcd', 'lcd_catalog');
                
                $stmt = $pdo->prepare("
                    INSERT INTO lcd_catalog (marque, reference, etat, modele, taille, resolution, connectique, prix, barcode)
                    VALUES (:marque, :reference, :etat, :modele, :taille, :resolution, :connectique, :prix, :barcode)
                ");
                $stmt->execute([
                    ':marque'     => $marque,
                    ':reference'  => $reference,
                    ':etat'       => $etat,
                    ':modele'     => $modele,
                    ':taille'     => $taille,
                    ':resolution' => $resolution,
                    ':connectique'=> $connectique,
                    ':prix'       => $prix,
                    ':barcode'    => $barcode,
                ]);
                $lcdId = $pdo->lastInsertId();
                
                // Générer et sauvegarder le QR Code
                $qrPath = generateQRCode($barcode, $lcdId, 'lcd');
                if ($qrPath) {
                    $stmt = $pdo->prepare("UPDATE lcd_catalog SET qr_code_path = :qr_path WHERE id = :id");
                    $stmt->execute([':qr_path' => $qrPath, ':id' => $lcdId]);
                } else {
                    $apiResponse['warning'] = 'Impossible de générer le QR code. L\'étiquette utilisera le code-barres.';
                }
            }

            $apiResponse['id'] = (int) $lcdId;
            if ($qrPath !== null) {
                $apiResponse['qr_code_path'] = $qrPath;
            }

            // 2) mouvement
            $stmt = $pdo->prepare("
                INSERT INTO lcd_moves (lcd_id, qty_delta, reason, reference, user_id)
                VALUES (:lcd_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':lcd_id'    => $lcdId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            
            // Enregistrer dans l'historique
            $details = sprintf('Ajout LCD: %s %s - Référence: %s - Taille: %d" - Quantité: %d', $marque, $modele, $reference, $taille, $qty);
            if ($ref !== $reference && $ref !== '') {
                $details .= ' - Référence mouvement: ' . $ref;
            }
            logStockAction($pdo, 'ajout_stock_lcd', $details);
            break;

        /* ===================== PC ===================== */
        case 'pc':
            $etat     = validateField(strtoupper(trim($payload['etat'] ?? '')), ['A', 'B', 'C'], 'état', false);
            $reference= trim($payload['reference'] ?? '');
            $marque   = trim($payload['marque']    ?? '');
            $modele   = trim($payload['modele']    ?? '');
            $cpu      = trim($payload['cpu']       ?? '');
            $ram      = validateField($payload['ram'] ?? '', ['4 GB', '8 GB', '16 GB', '32 GB', '64 GB'], 'RAM', true, 50);
            $stockage = validateField($payload['stockage'] ?? '', ['128 GB SSD', '256 GB SSD', '512 GB SSD', '1 TB SSD', '1 TB HDD', '2 TB HDD'], 'stockage', true, 100);
            $os       = validateField($payload['os'] ?? '', ['Windows 10', 'Windows 11', 'Linux', 'macOS', 'Sans OS'], 'OS', true, 100);
            $gpu      = trim($payload['gpu']       ?? '');
            $reseau   = trim($payload['reseau']    ?? '');
            $ports    = trim($payload['ports']     ?? '');
            $prix     = $payload['prix'] === '' ? null : (float)$payload['prix'];
            $qty      = (int)($payload['qty_delta'] ?? 0);
            $refMove  = trim($payload['reference_move'] ?? '');
            $ref      = $refMove !== '' ? $refMove : $reference;

            if ($reference === '' || $marque === '' || $modele === '' || $cpu === '' || $qty <= 0) {
                throw new RuntimeException('Champs obligatoires manquants (réf., marque, modèle, CPU, RAM, stockage, OS, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer dans pc_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM pc_catalog
                WHERE reference = :reference
                LIMIT 1
            ");
            $stmt->execute([':reference' => $reference]);
            $pcId = $stmt->fetchColumn();
            $qrPath = null;

            if ($pcId) {
                $stmt = $pdo->prepare("
                    UPDATE pc_catalog
                    SET etat = :etat,
                        marque = :marque,
                        modele = :modele,
                        cpu = :cpu,
                        ram = :ram,
                        stockage = :stockage,
                        os = :os,
                        gpu = :gpu,
                        reseau = :reseau,
                        ports = :ports,
                        prix = :prix
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':etat'      => $etat,
                    ':marque'    => $marque,
                    ':modele'    => $modele,
                    ':cpu'       => $cpu,
                    ':ram'       => $ram,
                    ':stockage'  => $stockage,
                    ':os'        => $os,
                    ':gpu'       => $gpu ?: null,
                    ':reseau'    => $reseau ?: null,
                    ':ports'     => $ports ?: null,
                    ':prix'      => $prix,
                    ':id'        => $pcId,
                ]);
            } else {
                // Générer un code-barres unique
                $barcode = generateBarcode($pdo, 'pc', 'pc_catalog');
                
                $stmt = $pdo->prepare("
                    INSERT INTO pc_catalog (etat, reference, marque, modele, cpu, ram, stockage, os, gpu, reseau, ports, prix, barcode)
                    VALUES (:etat, :reference, :marque, :modele, :cpu, :ram, :stockage, :os, :gpu, :reseau, :ports, :prix, :barcode)
                ");
                $stmt->execute([
                    ':etat'      => $etat,
                    ':reference' => $reference,
                    ':marque'    => $marque,
                    ':modele'    => $modele,
                    ':cpu'       => $cpu,
                    ':ram'       => $ram,
                    ':stockage'  => $stockage,
                    ':os'        => $os,
                    ':gpu'       => $gpu ?: null,
                    ':reseau'    => $reseau ?: null,
                    ':ports'     => $ports ?: null,
                    ':prix'      => $prix,
                    ':barcode'   => $barcode,
                ]);
                $pcId = $pdo->lastInsertId();
                
                // Générer et sauvegarder le QR Code
                $qrPath = generateQRCode($barcode, $pcId, 'pc');
                if ($qrPath) {
                    $stmt = $pdo->prepare("UPDATE pc_catalog SET qr_code_path = :qr_path WHERE id = :id");
                    $stmt->execute([':qr_path' => $qrPath, ':id' => $pcId]);
                } else {
                    $apiResponse['warning'] = 'Impossible de générer le QR code. L\'étiquette utilisera le code-barres.';
                }
            }

            $apiResponse['id'] = (int) $pcId;
            if ($qrPath !== null) {
                $apiResponse['qr_code_path'] = $qrPath;
            }

            // 2) mouvement
            $stmt = $pdo->prepare("
                INSERT INTO pc_moves (pc_id, qty_delta, reason, reference, user_id)
                VALUES (:pc_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':pc_id'     => $pcId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            
            // Enregistrer dans l'historique
            $details = sprintf('Ajout PC: %s %s - Référence: %s - CPU: %s - RAM: %s - Quantité: %d', $marque, $modele, $reference, $cpu, $ram, $qty);
            if ($ref !== $reference && $ref !== '') {
                $details .= ' - Référence mouvement: ' . $ref;
            }
            logStockAction($pdo, 'ajout_stock_pc', $details);
            break;

        default:
            throw new RuntimeException('Type inconnu.');
    }

    jsonResponse($apiResponse, 200);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('stock_add.php PDO error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('stock_add.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(['ok' => false, 'error' => 'Erreur lors de l\'ajout du produit'], 400);
}
