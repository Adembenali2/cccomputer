<?php
/**
 * Page d'impression d'étiquettes QR Code
 * Affiche une grille de 24 QR codes (3 colonnes x 8 lignes) pour une planche A4
 * 
 * @package CCComputer
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
require_once __DIR__ . '/../includes/helpers.php';

// Vérification des permissions
authorize_page('stock', []);

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

// Récupérer les paramètres
$type = trim($_GET['type'] ?? '');
$productId = (int)($_GET['id'] ?? 0);
$productName = trim($_GET['name'] ?? 'Produit');

if (empty($type) || $productId === 0) {
    die('Paramètres manquants');
}

// Récupérer les informations du produit et son QR Code
$barcode = '';
$qrCodePath = '';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($type) {
        case 'papier':
            $stmt = $pdo->prepare("SELECT barcode, qr_code_path FROM paper_catalog WHERE id = :id LIMIT 1");
            break;
        case 'toner':
        case 'toners':
            $stmt = $pdo->prepare("SELECT barcode, qr_code_path FROM toner_catalog WHERE id = :id LIMIT 1");
            break;
        case 'lcd':
            $stmt = $pdo->prepare("SELECT barcode, qr_code_path FROM lcd_catalog WHERE id = :id LIMIT 1");
            break;
        case 'pc':
            $stmt = $pdo->prepare("SELECT barcode, qr_code_path FROM pc_catalog WHERE id = :id LIMIT 1");
            break;
        default:
            die('Type de produit invalide');
    }
    
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        die('Produit non trouvé');
    }
    
    $barcode = $product['barcode'] ?? '';
    $qrCodePath = $product['qr_code_path'] ?? '';
    
    // Si pas de QR Code, générer une URL pour l'API
    if (empty($qrCodePath) && !empty($barcode)) {
        $qrCodePath = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($barcode);
    }
    
} catch (PDOException $e) {
    error_log('print_labels.php error: ' . $e->getMessage());
    die('Erreur de base de données');
}

// Priorité : qr_code_path (image locale) > fallback barcode via API externe
if (empty($qrCodePath) && !empty($barcode)) {
    $qrCodePath = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($barcode);
} elseif (empty($qrCodePath)) {
    die('Code-barres ou QR Code non disponible');
}

// Chemins : /uploads/qrcodes/<type>/<id>.png (nouveau) ou /assets/qr_codes/ (legacy) ou URL http
if (strpos($qrCodePath, 'http') === 0) {
    // Déjà une URL complète
} elseif (strpos($qrCodePath, '/') === 0) {
    // Chemin absolu depuis la racine (uploads/qrcodes ou assets/qr_codes)
} else {
    $qrCodePath = '/uploads/qrcodes/' . ($type === 'toners' ? 'toner' : $type) . '/' . basename($qrCodePath);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Étiquettes QR Code - <?= h($productName) ?></title>
    <style>
        /* Styles pour l'écran */
        @media screen {
            body {
                font-family: Arial, sans-serif;
                padding: 2rem;
                background: #f5f5f5;
            }
            .print-button {
                position: fixed;
                top: 1rem;
                right: 1rem;
                padding: 1rem 2rem;
                background: #3b82f6;
                color: white;
                border: none;
                border-radius: 0.5rem;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                z-index: 1000;
            }
            .print-button:hover {
                background: #2563eb;
            }
            .preview-info {
                margin-bottom: 2rem;
                padding: 1rem;
                background: white;
                border-radius: 0.5rem;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
        }
        
        /* Styles pour l'impression */
        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .print-button,
            .preview-info {
                display: none;
            }
            
            @page {
                size: A4;
                margin: 0.5cm;
            }
            
            .labels-grid {
                page-break-inside: avoid;
            }
        }
        
        /* Grille d'étiquettes - 3 colonnes x 8 lignes = 24 étiquettes */
        .labels-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-template-rows: repeat(8, 1fr);
            gap: 0.3cm;
            width: 100%;
            max-width: 21cm;
            margin: 0 auto;
        }
        
        .label {
            border: 1px solid #ddd;
            padding: 0.4cm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            page-break-inside: avoid;
            background: white;
        }
        
        .label-qr {
            width: 100%;
            max-width: 2.5cm;
            height: auto;
            margin-bottom: 0.2cm;
        }
        
        .label-name {
            font-size: 0.7rem;
            font-weight: 600;
            color: #1e293b;
            word-wrap: break-word;
            line-height: 1.2;
            max-width: 100%;
        }
        
        .label-barcode {
            font-size: 0.6rem;
            color: #64748b;
            margin-top: 0.1cm;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <!-- Bouton d'impression (masqué à l'impression) -->
    <button class="print-button" onclick="window.print()">🖨️ Imprimer</button>
    
    <!-- Info de prévisualisation (masquée à l'impression) -->
    <div class="preview-info">
        <h2>Prévisualisation - Étiquettes QR Code</h2>
        <p><strong>Produit :</strong> <?= h($productName) ?></p>
        <p><strong>Code-barres :</strong> <?= h($barcode) ?></p>
        <p><strong>Format :</strong> Planche A4 - 24 étiquettes (3 colonnes × 8 lignes)</p>
    </div>
    
    <!-- Grille de 24 étiquettes -->
    <div class="labels-grid">
        <?php for ($i = 0; $i < 24; $i++): ?>
            <div class="label">
                <img 
                    src="<?= h($qrCodePath) ?>" 
                    alt="QR Code <?= h($barcode) ?>"
                    class="label-qr"
                    onerror="this.style.display='none'; this.nextElementSibling.textContent='QR Code non disponible';" />
                <div class="label-name"><?= h($productName) ?></div>
                <div class="label-barcode"><?= h($barcode) ?></div>
            </div>
        <?php endfor; ?>
    </div>
    
</body>
</html>






