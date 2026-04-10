# PATCH 1 : HTML - Ajouter wrapper #maps-page

## Fichier : `public/maps.php`

### Modification ligne 46-189

**AVANT :**
```php
<body class="page-maps">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <!-- ... contenu ... -->
</main>
```

**APRÈS :**
```php
<body class="page-maps">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div id="maps-page">
<main class="page-container">
    <!-- ... contenu identique ... -->
</main>
</div> <!-- #maps-page -->
```

**Note :** Le `<script>` (ligne 191) reste APRÈS `</div>` (hors wrapper)

---

## Modification ligne 86-97 (Recherche améliorée)

**AVANT :**
```html
<div class="client-search">
    <input type="search"
           id="clientSearch"
           class="client-search-input"
           placeholder="Rechercher un client (nom, code, adresse)…"
           autocomplete="off">
    <div id="clientResults"
         class="client-results"
         aria-label="Résultats de recherche de clients">
        <!-- Rempli dynamiquement -->
    </div>
</div>
```

**APRÈS :**
```html
<div class="client-search-wrapper">
    <div class="client-search">
        <span class="client-search-icon">🔍</span>
        <input type="search"
               id="clientSearch"
               class="client-search-input"
               placeholder="Rechercher un client (nom, code, adresse)…"
               autocomplete="off"
               maxlength="120">
        <button type="button" class="client-search-clear" id="clientSearchClear" aria-label="Effacer la recherche">✕</button>
        <div id="clientResults"
             class="client-results"
             aria-label="Résultats de recherche de clients">
            <!-- Rempli dynamiquement -->
        </div>
    </div>
</div>
```

---

## Modification ligne 99-101 (Header clients sélectionnés)

**AVANT :**
```html
<div class="selected-clients" id="selectedClients">
    <p class="hint">Aucun client sélectionné pour le moment.</p>
</div>
```

**APRÈS :**
```html
<div class="selected-clients" id="selectedClients">
    <div class="selected-clients-header">
        <span>Clients sélectionnés</span>
        <span class="selected-clients-count" id="selectedClientsCount">0</span>
    </div>
    <p class="hint">Aucun client sélectionné pour le moment.</p>
</div>
```

---

## Modification ligne 177-186 (Toolbar + Filtres + Légende)

**AVANT :**
```html
<div class="map-toolbar">
    <div class="map-toolbar-left">
        <strong>Carte clients</strong> – <?= h((string)$totalClients) ?> client(s) enregistré(s)
    </div>
    <div class="map-toolbar-right">
        <span class="badge" id="badgeClients">Clients chargés : 0</span>
        <span class="badge" id="badgeStart">Départ : non défini</span>
    </div>
</div>
<div id="map" aria-label="Carte des clients"></div>
```

**APRÈS :**
```html
<div class="map-toolbar">
    <div class="map-toolbar-left">
        <strong>Carte clients</strong> – <?= h((string)$totalClients) ?> client(s) enregistré(s)
    </div>
    <div class="map-toolbar-right">
        <span class="badge" id="badgeClients">Clients chargés : 0</span>
        <span class="badge" id="badgeStart">Départ : non défini</span>
    </div>
    <div class="map-filters">
        <label class="map-filter-toggle" id="filterToggleAll">
            <input type="checkbox" checked data-filter="all">
            <span>Tous</span>
        </label>
        <label class="map-filter-toggle" id="filterToggleSav">
            <input type="checkbox" checked data-filter="sav">
            <span>🔧 SAV</span>
        </label>
        <label class="map-filter-toggle" id="filterToggleLivraison">
            <input type="checkbox" checked data-filter="livraison">
            <span>📦 Livraison</span>
        </label>
        <label class="map-filter-toggle" id="filterToggleNormal">
            <input type="checkbox" checked data-filter="normal">
            <span>✓ Normal</span>
        </label>
    </div>
</div>
<div id="map" aria-label="Carte des clients"></div>
<div class="map-legend" id="mapLegend">
    <div class="map-legend-item">
        <div class="map-legend-dot" style="background: #ef4444;"></div>
        <span>SAV + Livraison</span>
    </div>
    <div class="map-legend-item">
        <div class="map-legend-dot" style="background: #3b82f6;"></div>
        <span>Livraison</span>
    </div>
    <div class="map-legend-item">
        <div class="map-legend-dot" style="background: #eab308;"></div>
        <span>SAV</span>
    </div>
    <div class="map-legend-item">
        <div class="map-legend-dot" style="background: #16a34a;"></div>
        <span>Normal</span>
    </div>
</div>
```

---

## Modification ligne 66-76 (Sections repliables - optionnel)

**AVANT :**
```html
<div>
    <div class="section-title">1. Point de départ</div>
    <div class="btn-group">
        <!-- ... -->
    </div>
</div>
```

**APRÈS :**
```html
<div class="section-content">
    <div class="section-title" data-section="start">1. Point de départ</div>
    <div class="section-content">
        <div class="btn-group">
            <!-- ... -->
        </div>
        <div id="startInfo" class="hint">
            Aucun point de départ défini.
        </div>
    </div>
</div>
```

**Répéter pour sections 2 et 3 si souhaité.**

