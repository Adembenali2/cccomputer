<?php
// /public/scan_barcode.php
// Page dédiée au scanner de code-barres/QR codes
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('stock', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/helpers.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

/** PDO en mode exceptions **/
if (method_exists($pdo, 'setAttribute')) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {}
}

/** Helpers **/
function h($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Scanner Code-Barres - CCComputer</title>
    
    <link rel="stylesheet" href="/assets/css/main.css" />
    <link rel="stylesheet" href="/assets/css/stock.css" />
    
    <style>
        /* Style comme stock.php */
        .page-header {
            margin-bottom: 1.25rem;
        }
        
        .page-title {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-primary);
        }
        
        .page-sub {
            margin: 0.5rem 0 0 0;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }
        
        .scanner-wrapper {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }
        
        .scanner-controls {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .btn {
            flex: 1;
            min-width: 150px;
            padding: 0.55rem 0.9rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        
        .btn-primary:hover:not(:disabled) {
            background: var(--bg-secondary);
            border-color: var(--accent-primary);
        }
        
        .btn-secondary {
            background: var(--bg-primary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover:not(:disabled) {
            background: var(--bg-secondary);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        #reader {
            position: relative;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        #reader video,
        #reader canvas {
            width: 100% !important;
            max-width: 100% !important;
            height: auto !important;
            display: block !important;
            border-radius: 12px;
            object-fit: cover;
        }
        
        #reader #qr-shaded-region {
            border: 3px solid #10b981 !important;
            border-radius: 8px !important;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3),
                        0 0 20px rgba(16, 185, 129, 0.6) !important;
            animation: scanPulse 1.5s ease-in-out infinite;
        }
        
        @keyframes scanPulse {
            0%, 100% {
                box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3),
                            0 0 20px rgba(16, 185, 129, 0.6);
            }
            50% {
                box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.4),
                            0 0 30px rgba(16, 185, 129, 0.8);
            }
        }
        
        .status-message {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-top: 1rem;
        }
        
        .status-loading {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        /* Résultat du scan */
        .product-result {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-top: 1.5rem;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .product-result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .product-result-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .product-type-badge {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-papier { background: #dbeafe; color: #1e40af; }
        .badge-toner { background: #fce7f3; color: #9f1239; }
        .badge-lcd { background: #e0e7ff; color: #3730a3; }
        .badge-pc { background: #fef3c7; color: #92400e; }
        
        .product-info {
            display: grid;
            gap: 1rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #6b7280;
        }
        
        .info-value {
            font-weight: 700;
            color: #1f2937;
            text-align: right;
        }
        
        .qty-stock {
            font-size: 1.5rem;
            color: #059669;
        }
        
        .qty-stock.low {
            color: #dc2626;
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 0.9rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s;
            margin-bottom: 1rem;
        }
        
        .btn-back:hover {
            background: var(--bg-secondary);
            border-color: var(--accent-primary);
        }
        
        .btn-back svg {
            width: 18px;
            height: 18px;
        }
        
        @media (max-width: 768px) {
            .scanner-wrapper,
            .product-result {
                padding: 1rem;
            }
            
            #reader {
                min-height: 300px;
            }
        }
    </style>
</head>
<body class="page-stock">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
    <!-- Header simple comme stock.php -->
    <div class="page-header">
        <h2 class="page-title">Scanner Code-Barres</h2>
        <p class="page-sub">
            Positionnez le code-barres ou QR code dans le cadre pour scanner
        </p>
    </div>
    
    <a href="/public/stock.php" class="btn-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Retour au stock
    </a>
    
    <div class="scanner-wrapper">
            <div class="scanner-controls">
                <button id="startCameraScan" class="btn btn-primary">
                    📹 Démarrer la caméra
                </button>
                <button id="stopCameraScan" class="btn btn-secondary" style="display: none;">
                    ⏹️ Arrêter
                </button>
            </div>
            
            <div id="reader"></div>
            
            <div id="statusMessage" class="status-message" style="display: none;"></div>
        </div>
        
    </div>
    
    <div id="productResult" class="product-result" style="display: none;">
        <div class="product-result-header">
            <h2>Produit trouvé</h2>
            <span id="productTypeBadge" class="product-type-badge"></span>
        </div>
        <div class="product-info" id="productInfo">
            <!-- Rempli dynamiquement -->
        </div>
    </div>
</div><!-- /.page-container -->
    
    <!-- Chargement de la bibliothèque html5-qrcode -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
    (function() {
        'use strict';
        
        let html5QrcodeScanner = null;
        let isScanning = false;
        let lastScannedCode = null;
        let lastScanTime = 0;
        const SCAN_COOLDOWN_MS = 1000; // 1 seconde entre chaque scan
        
        const startCameraBtn = document.getElementById('startCameraScan');
        const stopCameraBtn = document.getElementById('stopCameraScan');
        const readerDiv = document.getElementById('reader');
        const statusMessage = document.getElementById('statusMessage');
        const productResult = document.getElementById('productResult');
        const productInfo = document.getElementById('productInfo');
        const productTypeBadge = document.getElementById('productTypeBadge');
        
        function showStatus(message, type = 'loading') {
            statusMessage.textContent = message;
            statusMessage.className = 'status-message status-' + type;
            statusMessage.style.display = 'block';
        }
        
        function hideStatus() {
            statusMessage.style.display = 'none';
        }
        
        function showError(message) {
            showStatus('❌ ' + message, 'error');
        }
        
        function showSuccess(message) {
            showStatus('✅ ' + message, 'success');
        }
        
        async function loadLibrary() {
            if (window.Html5Qrcode) {
                return window.Html5Qrcode;
            }
            
            // Attendre que la bibliothèque soit chargée
            let attempts = 0;
            const maxAttempts = 50;
            
            while (!window.Html5Qrcode && attempts < maxAttempts) {
                await new Promise(resolve => setTimeout(resolve, 100));
                attempts++;
            }
            
            if (!window.Html5Qrcode) {
                throw new Error('Bibliothèque html5-qrcode non chargée');
            }
            
            return window.Html5Qrcode;
        }
        
        async function startCameraScanning() {
            try {
                const Html5Qrcode = await loadLibrary();
                
                html5QrcodeScanner = new Html5Qrcode("reader");
                
                showStatus('⏳ Démarrage de la caméra...', 'loading');
                
                // Essayer la caméra arrière d'abord
                try {
                    await html5QrcodeScanner.start(
                        {
                            facingMode: 'environment'
                        },
                        {
                            fps: 10,
                            qrbox: { width: 300, height: 300 },
                            aspectRatio: 1.0
                        },
                        onScanSuccess,
                        onScanError
                    );
                    
                    isScanning = true;
                    startCameraBtn.style.display = 'none';
                    stopCameraBtn.style.display = 'flex';
                    hideStatus();
                    showSuccess('Caméra démarrée - Scannez un code');
                } catch (envError) {
                    console.warn('Caméra arrière non disponible, essai caméra avant:', envError);
                    
                    // Fallback sur la caméra avant
                    await html5QrcodeScanner.start(
                        {
                            facingMode: 'user'
                        },
                        {
                            fps: 10,
                            qrbox: { width: 300, height: 300 },
                            aspectRatio: 1.0
                        },
                        onScanSuccess,
                        onScanError
                    );
                    
                    isScanning = true;
                    startCameraBtn.style.display = 'none';
                    stopCameraBtn.style.display = 'flex';
                    hideStatus();
                    showSuccess('Caméra démarrée - Scannez un code');
                }
            } catch (error) {
                console.error('Erreur démarrage caméra:', error);
                showError('Erreur: ' + (error.message || 'Impossible de démarrer la caméra'));
                startCameraBtn.disabled = false;
                startCameraBtn.textContent = '📹 Démarrer la caméra';
            }
        }
        
        function stopScanning() {
            if (html5QrcodeScanner && isScanning) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner.clear();
                    isScanning = false;
                    startCameraBtn.style.display = 'flex';
                    stopCameraBtn.style.display = 'none';
                    hideStatus();
                }).catch(err => {
                    console.error('Erreur arrêt scanner:', err);
                });
            }
        }
        
        function onScanSuccess(decodedText, decodedResult) {
            if (!decodedText) return;
            
            const now = Date.now();
            if (decodedText === lastScannedCode && (now - lastScanTime) < SCAN_COOLDOWN_MS) {
                return; // Ignorer les scans répétés
            }
            
            lastScannedCode = decodedText;
            lastScanTime = now;
            
            showSuccess('Code détecté: ' + decodedText);
            
            // Récupérer les détails du produit
            fetchProductDetails(decodedText);
        }
        
        function onScanError(errorMessage) {
            // Ignorer les erreurs "No QR code found"
            if (errorMessage && 
                !errorMessage.includes('No QR code') && 
                !errorMessage.includes('NotFoundException') &&
                !errorMessage.includes('No MultiFormat Readers')) {
                console.warn('Erreur scan:', errorMessage);
            }
        }
        
        async function fetchProductDetails(barcode) {
            try {
                showStatus('⏳ Recherche du produit...', 'loading');
                
                const response = await fetch('/API/get_product_by_barcode.php?barcode=' + encodeURIComponent(barcode));
                const data = await response.json();
                
                if (!data.ok) {
                    throw new Error(data.error || 'Produit non trouvé');
                }
                
                // Afficher les détails du produit
                displayProductDetails(data.product, data.type);
                
                // NE PAS arrêter le scanner - permettre de scanner plusieurs codes rapidement
                
            } catch (error) {
                console.error('Erreur récupération produit:', error);
                showError('Erreur: ' + (error.message || 'Impossible de récupérer les détails'));
            }
        }
        
        function displayProductDetails(product, type) {
            const typeLabels = {
                'papier': { label: 'Papier', class: 'badge-papier' },
                'toner': { label: 'Toner', class: 'badge-toner' },
                'lcd': { label: 'LCD', class: 'badge-lcd' },
                'pc': { label: 'PC', class: 'badge-pc' }
            };
            
            const typeInfo = typeLabels[type] || { label: type, class: 'badge-papier' };
            
            productTypeBadge.textContent = typeInfo.label;
            productTypeBadge.className = 'product-type-badge ' + typeInfo.class;
            
            const qty = product.qty_stock || 0;
            const qtyClass = qty < 5 ? 'qty-stock low' : 'qty-stock';
            
            // Construire les détails selon le type de produit
            let detailsHtml = `
                <div class="info-row">
                    <span class="info-label">Nom</span>
                    <span class="info-value">${escapeHtml(product.nom || '—')}</span>
                </div>
            `;
            
            // Détails spécifiques selon le type
            if (type === 'papier' && product.poids) {
                detailsHtml += `
                    <div class="info-row">
                        <span class="info-label">Poids</span>
                        <span class="info-value">${escapeHtml(product.poids)}</span>
                    </div>
                `;
            }
            
            if (type === 'toner' && product.couleur) {
                detailsHtml += `
                    <div class="info-row">
                        <span class="info-label">Couleur</span>
                        <span class="info-value">${escapeHtml(product.couleur)}</span>
                    </div>
                `;
            }
            
            if (type === 'lcd') {
                if (product.taille) {
                    detailsHtml += `
                        <div class="info-row">
                            <span class="info-label">Taille</span>
                            <span class="info-value">${escapeHtml(product.taille)}"</span>
                        </div>
                    `;
                }
                if (product.resolution) {
                    detailsHtml += `
                        <div class="info-row">
                            <span class="info-label">Résolution</span>
                            <span class="info-value">${escapeHtml(product.resolution)}</span>
                        </div>
                    `;
                }
                if (product.reference) {
                    detailsHtml += `
                        <div class="info-row">
                            <span class="info-label">Référence</span>
                            <span class="info-value">${escapeHtml(product.reference)}</span>
                        </div>
                    `;
                }
            }
            
            if (type === 'pc') {
                if (product.cpu) {
                    detailsHtml += `
                        <div class="info-row">
                            <span class="info-label">CPU</span>
                            <span class="info-value">${escapeHtml(product.cpu)}</span>
                        </div>
                    `;
                }
                if (product.ram) {
                    detailsHtml += `
                        <div class="info-row">
                            <span class="info-label">RAM</span>
                            <span class="info-value">${escapeHtml(product.ram)}</span>
                        </div>
                    `;
                }
                if (product.reference) {
                    detailsHtml += `
                        <div class="info-row">
                            <span class="info-label">Référence</span>
                            <span class="info-value">${escapeHtml(product.reference)}</span>
                        </div>
                    `;
                }
            }
            
            detailsHtml += `
                <div class="info-row">
                    <span class="info-label">Code-barres</span>
                    <span class="info-value">${escapeHtml(product.barcode || '—')}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Stock disponible</span>
                    <span class="info-value ${qtyClass}">${qty} unité${qty > 1 ? 's' : ''}</span>
                </div>
            `;
            
            productInfo.innerHTML = detailsHtml;
            
            productResult.style.display = 'block';
            productResult.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            
            hideStatus();
            showSuccess('Produit trouvé et affiché');
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Event listeners
        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', async function() {
                startCameraBtn.disabled = true;
                startCameraBtn.textContent = '⏳ Chargement...';
                await startCameraScanning();
            });
        }
        
        if (stopCameraBtn) {
            stopCameraBtn.addEventListener('click', function() {
                stopScanning();
            });
        }
        
        // Nettoyer à la fermeture de la page
        window.addEventListener('beforeunload', function() {
            if (html5QrcodeScanner && isScanning) {
                html5QrcodeScanner.stop().catch(() => {});
            }
        });
    })();
    </script>
</body>
</html>

