# AMÉLIORATIONS MAPS.PHP - PLAN D'IMPLÉMENTATION

**Objectif:** Améliorer UI/UX et fonctionnalités sans casser l'existant  
**Contraintes:** Scoper sous `#maps-page`, ne pas toucher `main.css`, header, footer

---

## A) AMÉLIORATIONS UI/UX (12 propositions)

### 1. **Wrapper scoped + Responsive amélioré**
- Ajouter `#maps-page` wrapper pour isoler les styles
- Améliorer responsive (mobile-first)
- Sidebar repliable sur mobile

### 2. **Indicateurs de chargement visuels**
- Spinner pour géocodage en cours
- Barre de progression pour géocodage batch
- Skeleton loaders pour recherche

### 3. **Feedback utilisateur amélioré**
- Toasts/notifications pour actions (client ajouté, erreur, etc.)
- Messages d'erreur plus clairs avec icônes
- Confirmations pour actions destructives

### 4. **Accessibilité (ARIA, clavier)**
- Navigation clavier complète (Tab, Enter, Escape)
- Focus visible amélioré
- Labels ARIA manquants

### 5. **Sidebar améliorée**
- Sections repliables (accordéon)
- Scroll smooth avec indicateur
- Badge compteur clients sélectionnés

### 6. **Carte : contrôles visuels**
- Bouton "Reset zoom" visible
- Légende des couleurs de markers
- Contrôles de filtres visuels (toggle SAV/Livraison)

### 7. **Recherche améliorée**
- Icône de recherche dans l'input
- Bouton "Effacer" (X) dans l'input
- Historique de recherche (localStorage)

### 8. **Stats itinéraire : design amélioré**
- Icônes pour chaque stat (distance, durée, etc.)
- Animations au calcul
- Formatage nombres amélioré

### 9. **Clients sélectionnés : drag & drop**
- Réordonner par drag & drop (en plus des flèches)
- Animation lors du réordonnement
- Indicateur visuel de l'ordre

### 10. **Tooltips contextuels**
- Tooltips sur boutons (explications courtes)
- Tooltip sur markers (infos rapides au survol)
- Tooltip sur stats (explications)

### 11. **Mode sombre/clair (si applicable)**
- Toggle thème (si le site le supporte)
- Persistance préférence

### 12. **Animations subtiles**
- Transitions smooth sur interactions
- Fade-in pour nouveaux markers
- Slide-in pour sidebar sur mobile

---

## B) AMÉLIORATIONS FONCTIONNELLES (10 propositions)

### 1. **Clustering des markers**
- Utiliser Leaflet.markercluster
- Améliorer performance avec 100+ clients
- Zoom automatique au clic sur cluster

### 2. **Filtres visuels**
- Toggle pour afficher/masquer : SAV, Livraisons, Normaux
- Filtre par priorité (urgence)
- Compteur par type

### 3. **Recherche dans la zone visible**
- Bouton "Rechercher dans la zone visible"
- Filtre les clients selon viewport de la carte
- Mise à jour dynamique au zoom/pan

### 4. **localStorage : persistence**
- Sauvegarder clients sélectionnés
- Sauvegarder point de départ
- Restaurer au rechargement

### 5. **Export itinéraire**
- Export PDF (liste clients + route)
- Export CSV (coordonnées, ordre)
- Copier dans presse-papier (format texte)

### 6. **Géocodage amélioré**
- Retry manuel pour clients non trouvés
- Édition manuelle coordonnées
- Validation adresse avant géocodage

### 7. **Optimisation route avancée**
- Options de routage (éviter péages, autoroutes)
- Calcul temps réel (trafic) si disponible
- Comparaison plusieurs itinéraires

### 8. **Marqueurs personnalisables**
- Choisir icône par client
- Notes/commentaires par client
- Photos/pièces jointes (si applicable)

### 9. **Historique des itinéraires**
- Sauvegarder itinéraires calculés
- Liste des itinéraires précédents
- Recharger un itinéraire sauvegardé

### 10. **Partage itinéraire**
- Générer lien partageable (avec paramètres)
- QR code pour itinéraire
- Envoi par email (si applicable)

---

## C) PLAN D'IMPLÉMENTATION PAR ÉTAPES

### **STEP 1 : Wrapper + Structure de base** (30 min)
- Ajouter `#maps-page` wrapper dans HTML
- Vérifier que tout fonctionne
- **Risque:** Faible (juste wrapper)

### **STEP 2 : CSS Scoped** (1h)
- Préfixer tous les styles avec `#maps-page`
- Tester responsive
- **Risque:** Faible (CSS isolé)

### **STEP 3 : Indicateurs de chargement** (1h)
- Ajouter spinners/loaders
- Barre progression géocodage
- **Risque:** Faible (ajout seulement)

### **STEP 4 : Toasts/Notifications** (1h)
- Système de toasts simple
- Intégrer dans fonctions existantes
- **Risque:** Faible (non-bloquant)

### **STEP 5 : Clustering markers** (2h)
- Installer Leaflet.markercluster (CDN)
- Adapter `addClientToMap()`
- **Risque:** Moyen (peut affecter clics markers)

### **STEP 6 : Filtres visuels** (2h)
- Toggles SAV/Livraison/Normaux
- Fonction `filterMarkers()`
- **Risque:** Faible (ajout seulement)

### **STEP 7 : localStorage persistence** (1h)
- Sauvegarder `selectedClients`, `startPoint`
- Restaurer au chargement
- **Risque:** Faible (fallback si localStorage vide)

### **STEP 8 : Recherche zone visible** (1.5h)
- Fonction `getClientsInBounds()`
- Bouton + listener
- **Risque:** Faible (ajout seulement)

### **STEP 9 : Export itinéraire** (2h)
- Export CSV (client-side)
- Export PDF (si bibliothèque disponible)
- **Risque:** Faible (nouvelle fonctionnalité)

### **STEP 10 : Accessibilité** (1.5h)
- Navigation clavier
- ARIA labels
- Focus visible
- **Risque:** Faible (amélioration)

### **STEP 11 : Améliorations UI finales** (2h)
- Drag & drop clients
- Tooltips
- Animations
- **Risque:** Moyen (peut affecter UX)

### **STEP 12 : Tests & Polish** (1h)
- Tester toutes les fonctionnalités
- Corriger bugs
- Optimiser performance
- **Risque:** Faible (validation)

---

## D) MODIFICATIONS EXACTES

### **PATCH 1 : HTML - Ajouter wrapper #maps-page**

**Fichier:** `public/maps.php`

**Ligne 46-189 :** Remplacer `<body class="page-maps">` jusqu'à `</main>` par :

```php
<body class="page-maps">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div id="maps-page">
<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">Carte & planification de tournée</h1>
        <p class="page-sub">
            Visualisez vos clients sur une carte et préparez un itinéraire (départ de chez vous + plusieurs clients).<br>
            Version <strong>100% gratuite</strong> basée sur <strong>OpenStreetMap</strong> + <strong>OSRM</strong> (pas de clé API, pas de CB).
        </p>
    </header>

    <section class="maps-layout">
        <!-- PANNEAU GAUCHE : PARAMÈTRES / CLIENTS -->
        <aside class="maps-panel" aria-label="Panneau de planification de tournée">
            <!-- ... reste identique ... -->
        </aside>

        <!-- PANNEAU DROIT : CARTE -->
        <section class="map-wrapper">
            <!-- ... reste identique ... -->
        </section>
    </section>
</main>
</div> <!-- #maps-page -->
```

**Ligne 191 :** Le `<script>` reste après `</div>` (hors wrapper)

---

### **PATCH 2 : CSS - Scoper tous les styles sous #maps-page**

**Fichier:** `assets/css/maps.css`

**Remplacer TOUT le contenu par :**

```css
/* ============================================
   STYLES MAPS.PHP - SCOPED SOUS #maps-page
   ============================================ */

#maps-page {
  /* Variables locales (si besoin) */
  --maps-accent: #3b82f6;
  --maps-success: #16a34a;
  --maps-error: #dc2626;
  --maps-warning: #eab308;
}

#maps-page .page-sub {
  color: var(--text-secondary);
  margin-top: -0.5rem;
}

/* Layout principal : panneau + carte */
#maps-page .maps-layout {
  margin-top: 1rem;
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 1rem;
}

@media (max-width: 960px) {
  #maps-page .maps-layout {
    grid-template-columns: 1fr;
  }
  
  /* Sidebar repliable sur mobile */
  #maps-page .maps-panel {
    max-height: 60vh;
    overflow-y: auto;
  }
}

/* Panneau latéral gauche */
#maps-page .maps-panel {
  background: var(--bg-primary);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  position: relative;
}

#maps-page .maps-panel h2 {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
}

#maps-page .maps-panel small {
  color: var(--text-secondary);
}

#maps-page .maps-panel .section-title {
  font-size: 0.9rem;
  font-weight: 600;
  margin-top: 0.75rem;
  margin-bottom: 0.25rem;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  user-select: none;
}

#maps-page .maps-panel .section-title::before {
  content: '▼';
  font-size: 0.7rem;
  transition: transform 0.2s;
  color: var(--text-secondary);
}

#maps-page .maps-panel .section-title.collapsed::before {
  transform: rotate(-90deg);
}

#maps-page .maps-panel .section-content {
  transition: max-height 0.3s ease, opacity 0.2s;
  overflow: hidden;
}

#maps-page .maps-panel .section-content.collapsed {
  max-height: 0;
  opacity: 0;
  margin: 0;
  padding: 0;
}

#maps-page .maps-panel .hint {
  font-size: 0.8rem;
  color: var(--text-secondary);
}

/* Boutons du panneau */
#maps-page .maps-panel button,
#maps-page .maps-panel .btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 0.45rem 0.75rem;
  border-radius: var(--radius-md);
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  color: var(--text-primary);
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  box-shadow: var(--shadow-sm);
  transition: all 0.2s;
}

#maps-page .maps-panel button:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}

#maps-page .maps-panel button:active:not(:disabled) {
  transform: translateY(0);
}

#maps-page .maps-panel button:focus-visible {
  outline: 2px solid var(--maps-accent);
  outline-offset: 2px;
}

#maps-page .maps-panel button.primary {
  background: var(--maps-accent);
  border-color: var(--maps-accent);
  color: #fff;
}

#maps-page .maps-panel button.primary:hover:not(:disabled) {
  filter: brightness(.96);
}

#maps-page .maps-panel button.secondary {
  background: var(--bg-secondary);
  border-color: var(--border-color);
  color: var(--text-secondary);
}

#maps-page .maps-panel button.secondary:disabled {
  opacity: 0.5;
  cursor: default;
  box-shadow: none;
}

#maps-page .maps-panel .btn-group {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

/* Options d'itinéraire */
#maps-page .route-options {
  margin-top: 0.4rem;
}

#maps-page .route-option {
  font-size: 0.8rem;
  color: var(--text-secondary);
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

#maps-page .route-option input[type="checkbox"] {
  accent-color: var(--maps-accent);
}

/* Bouton Google Maps */
#maps-page .route-extra {
  margin-top: 0.4rem;
}

/* Recherche de clients */
#maps-page .client-search {
  position: relative;
  margin-bottom: 0.5rem;
}

#maps-page .client-search-input {
  width: 100%;
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  color: var(--text-primary);
  border-radius: var(--radius-md);
  padding: 0.55rem 0.75rem 0.55rem 2.5rem;
  font-size: 0.9rem;
  transition: border-color 0.2s, box-shadow 0.2s;
}

#maps-page .client-search-input:focus {
  outline: none;
  border-color: var(--maps-accent);
  box-shadow: 0 0 0 2px rgba(59,130,246,0.3);
}

#maps-page .client-search-wrapper {
  position: relative;
}

#maps-page .client-search-icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-secondary);
  pointer-events: none;
  font-size: 0.9rem;
}

#maps-page .client-search-clear {
  position: absolute;
  right: 0.5rem;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  color: var(--text-secondary);
  cursor: pointer;
  padding: 0.25rem;
  display: none;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: background 0.2s;
}

#maps-page .client-search-clear:hover {
  background: var(--bg-secondary);
}

#maps-page .client-search-clear.visible {
  display: flex;
}

#maps-page .client-results {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  background: var(--bg-primary);
  border-radius: var(--radius-md);
  border: 1px solid var(--border-color);
  box-shadow: var(--shadow-md);
  max-height: 220px;
  overflow: auto;
  display: none;
  animation: fadeIn 0.2s;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

#maps-page .client-result-item {
  padding: 0.4rem 0.6rem;
  cursor: pointer;
  font-size: 0.85rem;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  transition: background 0.15s;
}

#maps-page .client-result-item strong {
  color: var(--text-primary);
}

#maps-page .client-result-item span {
  color: var(--text-secondary);
  font-size: 0.78rem;
}

#maps-page .client-result-item:hover,
#maps-page .client-result-item:focus {
  background: var(--bg-secondary);
  outline: none;
}

#maps-page .client-result-item.empty {
  cursor: default;
}

#maps-page .client-result-item.loading {
  cursor: default;
  color: var(--text-secondary);
  font-style: italic;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* Spinner loader */
#maps-page .spinner {
  width: 16px;
  height: 16px;
  border: 2px solid var(--border-color);
  border-top-color: var(--maps-accent);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Clients sélectionnés */
#maps-page .selected-clients {
  border-radius: var(--radius-md);
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  padding: 0.4rem 0.5rem;
  max-height: 260px;
  overflow: auto;
  position: relative;
}

#maps-page .selected-clients-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
  font-size: 0.85rem;
  font-weight: 600;
}

#maps-page .selected-clients-count {
  background: var(--maps-accent);
  color: white;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.75rem;
}

#maps-page .selected-client-chip {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.4rem 0.3rem;
  border-radius: var(--radius-md);
  background: var(--bg-primary);
  border: 1px solid var(--border-color);
  margin-bottom: 0.35rem;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
}

#maps-page .selected-client-chip:last-child {
  margin-bottom: 0;
}

#maps-page .selected-client-chip:hover {
  box-shadow: var(--shadow-sm);
  transform: translateX(2px);
}

#maps-page .selected-client-chip.dragging {
  opacity: 0.5;
}

#maps-page .selected-client-chip.drag-over {
  border-color: var(--maps-accent);
  background: rgba(59,130,246,0.1);
}

#maps-page .selected-client-main {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  font-size: 0.85rem;
  flex: 1;
}

#maps-page .selected-client-main strong {
  color: var(--text-primary);
}

#maps-page .selected-client-main span {
  color: var(--text-secondary);
  font-size: 0.78rem;
}

#maps-page .selected-client-controls {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.25rem;
}

#maps-page .selected-client-controls label {
  font-size: 0.78rem;
  color: var(--text-secondary);
}

#maps-page .selected-client-controls select {
  margin-left: 0.25rem;
  font-size: 0.78rem;
  padding: 0.15rem 0.35rem;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border-color);
  background: var(--bg-primary);
  color: var(--text-primary);
}

#maps-page .chip-remove {
  border-radius: 999px;
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  color: var(--text-secondary);
  font-size: 0.75rem;
  padding: 0.1rem 0.4rem;
  cursor: pointer;
  transition: all 0.2s;
}

#maps-page .chip-remove:hover {
  background: var(--maps-error);
  color: white;
  border-color: var(--maps-error);
}

#maps-page .chip-move {
  border-radius: 999px;
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  color: var(--text-secondary);
  font-size: 0.7rem;
  padding: 0.1rem 0.3rem;
  cursor: pointer;
  transition: all 0.2s;
}

#maps-page .chip-move:hover {
  background: var(--maps-accent);
  color: white;
  border-color: var(--maps-accent);
}

/* Stats itinéraire */
#maps-page .maps-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.4rem;
  margin-top: 0.5rem;
}

#maps-page .maps-stat {
  background: var(--bg-secondary);
  border-radius: var(--radius-md);
  padding: 0.4rem 0.6rem;
  font-size: 0.8rem;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  transition: transform 0.2s;
}

#maps-page .maps-stat.updated {
  animation: pulse 0.5s;
}

@keyframes pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
}

#maps-page .maps-stat-label {
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: .05em;
  font-size: 0.72rem;
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

#maps-page .maps-stat-value {
  font-weight: 700;
  font-size: 1rem;
}

/* Résumé des étapes */
#maps-page .route-steps {
  margin-top: 0.6rem;
  padding-top: 0.4rem;
  border-top: 1px dashed var(--border-color);
  font-size: 0.8rem;
  color: var(--text-secondary);
}

#maps-page .route-steps ul {
  padding-left: 1.2rem;
  margin: 0.25rem 0 0;
}

#maps-page .route-steps li {
  margin-bottom: 0.25rem;
}

/* Détails tour par tour */
#maps-page .route-turns {
  margin-top: 0.6rem;
  padding-top: 0.4rem;
  border-top: 1px dashed var(--border-color);
  font-size: 0.8rem;
  color: var(--text-secondary);
  max-height: 260px;
  overflow: auto;
}

#maps-page .route-turns-leg {
  margin-bottom: 0.5rem;
}

#maps-page .route-turns-leg-title {
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 0.25rem;
}

#maps-page .route-turns-list {
  list-style: none;
  padding-left: 0;
  margin: 0;
}

#maps-page .route-turns-step {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin-bottom: 0.25rem;
}

#maps-page .route-turns-step-index {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 999px;
  background: var(--bg-secondary);
  border: 1px solid var(--border-color);
  font-size: 0.7rem;
  color: var(--text-secondary);
  flex-shrink: 0;
}

#maps-page .route-turns-step-text {
  flex: 1;
}

/* Bloc carte à droite */
#maps-page .map-wrapper {
  background: var(--bg-primary);
  border-radius: var(--radius-lg);
  border: 1px solid var(--border-color);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

/* Barre au-dessus de la carte */
#maps-page .map-toolbar {
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid var(--border-color);
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

#maps-page .map-toolbar-left {
  font-size: 0.85rem;
  color: var(--text-secondary);
}

#maps-page .map-toolbar-right {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
  align-items: center;
}

#maps-page .map-toolbar .badge {
  padding: 0.2rem 0.6rem;
  font-size: 0.75rem;
  border-radius: 999px;
  background: var(--bg-secondary);
  border: 1px solid var(--border-color);
  color: var(--text-secondary);
}

/* Filtres visuels */
#maps-page .map-filters {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-top: 0.5rem;
}

#maps-page .map-filter-toggle {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.3rem 0.6rem;
  border-radius: var(--radius-md);
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  color: var(--text-secondary);
  font-size: 0.8rem;
  cursor: pointer;
  transition: all 0.2s;
}

#maps-page .map-filter-toggle:hover {
  background: var(--bg-primary);
}

#maps-page .map-filter-toggle.active {
  background: var(--maps-accent);
  color: white;
  border-color: var(--maps-accent);
}

#maps-page .map-filter-toggle input[type="checkbox"] {
  margin: 0;
  accent-color: white;
}

/* Element carte Leaflet */
#maps-page #map {
  width: 100%;
  height: min(70vh, 700px);
  min-height: 420px;
}

/* Légende markers */
#maps-page .map-legend {
  position: absolute;
  bottom: 1rem;
  right: 1rem;
  background: white;
  padding: 0.75rem;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-md);
  z-index: 1000;
  font-size: 0.8rem;
  border: 1px solid var(--border-color);
}

#maps-page .map-legend-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

#maps-page .map-legend-item:last-child {
  margin-bottom: 0;
}

#maps-page .map-legend-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  border: 2px solid white;
  box-shadow: 0 0 0 1px rgba(0,0,0,0.25);
}

/* Icônes de priorité */
#maps-page .priority-marker {
  border-radius: 50%;
}

#maps-page .priority-dot {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 2px solid #ffffff;
  box-shadow: 0 0 0 1px rgba(0,0,0,0.25);
}

/* Messages */
#maps-page .maps-message {
  font-size: 0.8rem;
  color: var(--text-secondary);
  padding: 0.5rem;
  border-radius: var(--radius-md);
  background: var(--bg-secondary);
  border-left: 3px solid transparent;
}

#maps-page .maps-message.alert {
  color: var(--maps-error);
  border-left-color: var(--maps-error);
  background: rgba(220,38,38,0.1);
}

#maps-page .maps-message.success {
  color: var(--maps-success);
  border-left-color: var(--maps-success);
  background: rgba(22,163,74,0.1);
}

#maps-page .maps-message.hint {
  border-left-color: var(--text-secondary);
}

/* Toasts/Notifications */
#maps-page .toast-container {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 10000;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 400px;
}

#maps-page .toast {
  padding: 0.75rem 1rem;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-lg);
  background: var(--bg-primary);
  border: 1px solid var(--border-color);
  display: flex;
  align-items: center;
  gap: 0.75rem;
  animation: slideInRight 0.3s;
  min-width: 250px;
}

@keyframes slideInRight {
  from {
    transform: translateX(100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

#maps-page .toast.success {
  border-left: 3px solid var(--maps-success);
}

#maps-page .toast.error {
  border-left: 3px solid var(--maps-error);
}

#maps-page .toast.info {
  border-left: 3px solid var(--maps-accent);
}

#maps-page .toast-icon {
  font-size: 1.2rem;
}

#maps-page .toast-message {
  flex: 1;
  font-size: 0.9rem;
}

#maps-page .toast-close {
  background: transparent;
  border: none;
  color: var(--text-secondary);
  cursor: pointer;
  padding: 0.25rem;
  font-size: 1rem;
  line-height: 1;
}

/* Barre de progression */
#maps-page .progress-bar {
  width: 100%;
  height: 4px;
  background: var(--bg-secondary);
  border-radius: 2px;
  overflow: hidden;
  margin-top: 0.5rem;
}

#maps-page .progress-bar-fill {
  height: 100%;
  background: var(--maps-accent);
  transition: width 0.3s;
  border-radius: 2px;
}

/* Clients non trouvés */
#maps-page .not-found-clients {
  border-radius: var(--radius-md);
  border: 1px solid var(--border-color);
  background: var(--bg-secondary);
  padding: 0.4rem 0.5rem;
  max-height: 260px;
  overflow: auto;
  margin-top: 0.5rem;
}

#maps-page .not-found-client-item {
  padding: 0.4rem 0.5rem;
  border-radius: var(--radius-md);
  background: var(--bg-primary);
  border: 1px solid var(--border-color);
  margin-bottom: 0.35rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

#maps-page .not-found-client-item:last-child {
  margin-bottom: 0;
}

#maps-page .not-found-client-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
}

#maps-page .not-found-client-info strong {
  color: var(--text-primary);
  font-size: 0.85rem;
}

#maps-page .not-found-client-code {
  color: var(--text-secondary);
  font-size: 0.78rem;
}

#maps-page .not-found-client-address {
  color: var(--text-secondary);
  font-size: 0.78rem;
}

/* Loading skeleton */
#maps-page .skeleton {
  background: linear-gradient(90deg, var(--bg-secondary) 25%, var(--bg-primary) 50%, var(--bg-secondary) 75%);
  background-size: 200% 100%;
  animation: loading 1.5s infinite;
  border-radius: var(--radius-md);
}

@keyframes loading {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* Responsive amélioré */
@media (max-width: 768px) {
  #maps-page .maps-layout {
    grid-template-columns: 1fr;
  }
  
  #maps-page .maps-panel {
    order: 2;
    max-height: 50vh;
  }
  
  #maps-page .map-wrapper {
    order: 1;
  }
  
  #maps-page #map {
    height: 50vh;
    min-height: 300px;
  }
  
  #maps-page .toast-container {
    left: 1rem;
    right: 1rem;
    max-width: none;
  }
}
```

---

### **PATCH 3 : HTML - Améliorations structure (recherche, filtres, légende)**

**Fichier:** `public/maps.php`

**Ligne 86-97 :** Remplacer la recherche par :

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

**Ligne 99-101 :** Remplacer par :

```html
<div class="selected-clients" id="selectedClients">
    <div class="selected-clients-header">
        <span>Clients sélectionnés</span>
        <span class="selected-clients-count" id="selectedClientsCount">0</span>
    </div>
    <p class="hint">Aucun client sélectionné pour le moment.</p>
</div>
```

**Ligne 177-185 :** Remplacer la toolbar par :

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

**Ligne 66-76 :** Ajouter des classes pour sections repliables :

```html
<div class="section-content">
    <div class="section-title" data-section="start">1. Point de départ</div>
    <div class="section-content">
        <div class="btn-group">
            <button type="button" id="btnGeo" class="primary">📍 Ma position</button>
            <button type="button" id="btnClickStart">🖱️ Choisir sur la carte</button>
            <button type="button" id="btnClearStart">❌ Effacer</button>
        </div>
        <div id="startInfo" class="hint">
            Aucun point de départ défini.
        </div>
    </div>
</div>
```

(Faire pareil pour sections 2 et 3)

---

### **PATCH 4 : JS - Nouvelles fonctions (à ajouter AVANT le script existant)**

**Fichier:** `public/maps.php`

**Ligne 191 :** Ajouter AVANT le script existant :

```html
<script>
// ============================================
// AMÉLIORATIONS MAPS - NOUVELLES FONCTIONNALITÉS
// ============================================

// Système de toasts
const ToastManager = {
    container: null,
    
    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            this.container.setAttribute('aria-live', 'polite');
            this.container.setAttribute('aria-atomic', 'true');
            document.getElementById('maps-page').appendChild(this.container);
        }
    },
    
    show(message, type = 'info', duration = 3000) {
        this.init();
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icons = {
            success: '✅',
            error: '❌',
            info: 'ℹ️',
            warning: '⚠️'
        };
        
        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-message">${escapeHtml(message)}</span>
            <button type="button" class="toast-close" aria-label="Fermer">×</button>
        `;
        
        const closeBtn = toast.querySelector('.toast-close');
        const close = () => {
            toast.style.animation = 'slideInRight 0.3s reverse';
            setTimeout(() => toast.remove(), 300);
        };
        
        closeBtn.addEventListener('click', close);
        
        if (duration > 0) {
            setTimeout(close, duration);
        }
        
        this.container.appendChild(toast);
    }
};

// localStorage persistence
const StorageManager = {
    KEY_SELECTED_CLIENTS: 'maps_selected_clients',
    KEY_START_POINT: 'maps_start_point',
    
    saveSelectedClients(clients) {
        try {
            localStorage.setItem(this.KEY_SELECTED_CLIENTS, JSON.stringify(clients));
        } catch (e) {
            console.warn('localStorage save failed:', e);
        }
    },
    
    loadSelectedClients() {
        try {
            const data = localStorage.getItem(this.KEY_SELECTED_CLIENTS);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            console.warn('localStorage load failed:', e);
            return null;
        }
    },
    
    saveStartPoint(point) {
        try {
            localStorage.setItem(this.KEY_START_POINT, JSON.stringify(point));
        } catch (e) {
            console.warn('localStorage save failed:', e);
        }
    },
    
    loadStartPoint() {
        try {
            const data = localStorage.getItem(this.KEY_START_POINT);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            console.warn('localStorage load failed:', e);
            return null;
        }
    },
    
    clear() {
        try {
            localStorage.removeItem(this.KEY_SELECTED_CLIENTS);
            localStorage.removeItem(this.KEY_START_POINT);
        } catch (e) {
            console.warn('localStorage clear failed:', e);
        }
    }
};

// Filtres markers
const FilterManager = {
    activeFilters: new Set(['all', 'sav', 'livraison', 'normal']),
    
    init() {
        const toggles = document.querySelectorAll('#maps-page .map-filter-toggle input[type="checkbox"]');
        toggles.forEach(toggle => {
            toggle.addEventListener('change', () => {
                const filter = toggle.dataset.filter;
                if (toggle.checked) {
                    this.activeFilters.add(filter);
                } else {
                    this.activeFilters.delete(filter);
                }
                this.applyFilters();
            });
        });
    },
    
    applyFilters() {
        Object.values(clientMarkers).forEach(marker => {
            const clientId = marker.options.clientId;
            const client = clientsCache.get(clientId);
            if (!client) return;
            
            let visible = false;
            
            if (this.activeFilters.has('all')) {
                visible = true;
            } else {
                const hasSav = client.hasSav || false;
                const hasLivraison = client.hasLivraison || false;
                
                if (this.activeFilters.has('sav') && hasSav) visible = true;
                if (this.activeFilters.has('livraison') && hasLivraison) visible = true;
                if (this.activeFilters.has('normal') && !hasSav && !hasLivraison) visible = true;
            }
            
            if (visible) {
                map.addLayer(marker);
            } else {
                map.removeLayer(marker);
            }
        });
    }
};

// Recherche dans zone visible
function searchInVisibleBounds() {
    if (!map) return;
    
    const bounds = map.getBounds();
    const visibleClients = Array.from(clientsCache.values()).filter(client => {
        if (!isValidCoordinate(client.lat, client.lng)) return false;
        return bounds.contains([client.lat, client.lng]);
    });
    
    ToastManager.show(`${visibleClients.length} client(s) dans la zone visible`, 'info');
    
    // Optionnel: highlight les markers visibles
    Object.values(clientMarkers).forEach(marker => {
        const clientId = marker.options.clientId;
        const client = clientsCache.get(clientId);
        if (client && bounds.contains([client.lat, client.lng])) {
            marker.openPopup();
        }
    });
}

// Export itinéraire
function exportRoute(format = 'csv') {
    if (!lastOrderedStops.length) {
        ToastManager.show('Aucun itinéraire à exporter', 'warning');
        return;
    }
    
    if (format === 'csv') {
        const headers = ['Ordre', 'Nom', 'Code', 'Adresse', 'Latitude', 'Longitude'];
        const rows = [
            ['Départ', 'Point de départ', '', startPoint ? `${startPoint[0]},${startPoint[1]}` : '', startPoint?.[0] || '', startPoint?.[1] || ''],
            ...lastOrderedStops.map((client, idx) => [
                idx + 1,
                client.name || '',
                client.code || '',
                client.address || '',
                client.lat || '',
                client.lng || ''
            ])
        ];
        
        const csv = [
            headers.join(','),
            ...rows.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
        ].join('\n');
        
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `itineraire_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        URL.revokeObjectURL(url);
        
        ToastManager.show('Itinéraire exporté en CSV', 'success');
    }
}

// Sections repliables
function initCollapsibleSections() {
    const sections = document.querySelectorAll('#maps-page .section-title[data-section]');
    sections.forEach(title => {
        title.addEventListener('click', () => {
            const content = title.nextElementSibling;
            if (content && content.classList.contains('section-content')) {
                title.classList.toggle('collapsed');
                content.classList.toggle('collapsed');
            }
        });
    });
}

// Barre de progression géocodage
function updateGeocodeProgress(current, total) {
    let progressBar = document.getElementById('geocodeProgressBar');
    if (!progressBar) {
        const container = document.getElementById('notFoundClientsSection');
        if (container) {
            progressBar = document.createElement('div');
            progressBar.id = 'geocodeProgressBar';
            progressBar.className = 'progress-bar';
            progressBar.innerHTML = '<div class="progress-bar-fill" style="width: 0%"></div>';
            container.parentNode.insertBefore(progressBar, container);
        }
    }
    
    if (progressBar) {
        const fill = progressBar.querySelector('.progress-bar-fill');
        const percent = total > 0 ? (current / total) * 100 : 0;
        fill.style.width = percent + '%';
    }
}

// Initialisation au chargement
document.addEventListener('DOMContentLoaded', () => {
    // Restaurer depuis localStorage
    const savedClients = StorageManager.loadSelectedClients();
    if (savedClients && savedClients.length > 0) {
        // Restaurer après chargement des clients
        setTimeout(() => {
            savedClients.forEach(saved => {
                const client = clientsCache.get(saved.id);
                if (client) {
                    selectedClients.push({ id: client.id, priority: saved.priority || 1 });
                }
            });
            renderSelectedClients();
            ToastManager.show('Itinéraire restauré depuis la sauvegarde', 'info');
        }, 1000);
    }
    
    const savedStart = StorageManager.loadStartPoint();
    if (savedStart && savedStart.length === 2) {
        setStartPoint(savedStart, 'Point restauré');
    }
    
    // Initialiser sections repliables
    initCollapsibleSections();
    
    // Initialiser filtres
    FilterManager.init();
    
    // Bouton recherche zone visible
    const btnSearchBounds = document.createElement('button');
    btnSearchBounds.type = 'button';
    btnSearchBounds.className = 'secondary';
    btnSearchBounds.textContent = '🔍 Zone visible';
    btnSearchBounds.addEventListener('click', searchInVisibleBounds);
    document.querySelector('.map-toolbar-right')?.appendChild(btnSearchBounds);
    
    // Bouton export
    const btnExport = document.createElement('button');
    btnExport.type = 'button';
    btnExport.className = 'secondary';
    btnExport.textContent = '📥 Exporter';
    btnExport.addEventListener('click', () => exportRoute('csv'));
    document.querySelector('.route-extra')?.appendChild(btnExport);
    
    // Bouton effacer recherche
    const clearBtn = document.getElementById('clientSearchClear');
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            clientSearchInput.value = '';
            clientResultsEl.innerHTML = '';
            clientResultsEl.style.display = 'none';
            clearBtn.classList.remove('visible');
            clientSearchInput.focus();
        });
        
        clientSearchInput.addEventListener('input', () => {
            clearBtn.classList.toggle('visible', clientSearchInput.value.length > 0);
        });
    }
});

// Intercepter les fonctions existantes pour ajouter toasts et persistence
const originalAddClientToRoute = window.addClientToRoute;
if (originalAddClientToRoute) {
    window.addClientToRoute = async function(client) {
        const result = await originalAddClientToRoute.call(this, client);
        if (result !== false) {
            StorageManager.saveSelectedClients(selectedClients);
            const count = selectedClients.length;
            document.getElementById('selectedClientsCount').textContent = count;
            ToastManager.show(`Client "${client.name}" ajouté`, 'success', 2000);
        }
        return result;
    };
}

const originalSetStartPoint = window.setStartPoint;
if (originalSetStartPoint) {
    window.setStartPoint = function(latlng, label) {
        originalSetStartPoint.call(this, latlng, label);
        StorageManager.saveStartPoint(startPoint);
        ToastManager.show('Point de départ défini', 'success', 2000);
    };
}

// Mettre à jour le compteur clients sélectionnés
const originalRenderSelectedClients = window.renderSelectedClients;
if (originalRenderSelectedClients) {
    window.renderSelectedClients = function() {
        originalRenderSelectedClients.call(this);
        const count = selectedClients.length;
        document.getElementById('selectedClientsCount').textContent = count;
        StorageManager.saveSelectedClients(selectedClients);
    };
}

</script>
```

---

### **PATCH 5 : JS - Modifications dans le script existant**

**Fichier:** `public/maps.php`

**Dans la fonction `loadAllClients()` (ligne ~255) :** Ajouter après le message de succès :

```javascript
// Ajouter toast
if (typeof ToastManager !== 'undefined') {
    ToastManager.show(`${clientsWithCoords} client(s) chargés`, 'success', 2000);
}
```

**Dans la fonction `geocodeClientsInBackground()` (ligne ~367) :** Modifier `updateProgress()` :

```javascript
const updateProgress = () => {
    if (processed < clientsToGeocode.length) {
        routeMessageEl.textContent = `Géocodage en cours : ${processed}/${clientsToGeocode.length} client(s) traités (${found} trouvé(s))…`;
        // Mettre à jour barre de progression
        if (typeof updateGeocodeProgress !== 'undefined') {
            updateGeocodeProgress(processed, clientsToGeocode.length);
        }
    }
};
```

**Dans la fonction `addClientToMap()` (ligne ~651) :** Ajouter `clientId` aux options du marker :

```javascript
const marker = L.marker([client.lat, client.lng], {
    icon: createMarkerIcon(markerType),
    clientId: client.id  // Pour filtres
}).addTo(map);
```

**Dans la fonction `renderSelectedClients()` (ligne ~744) :** Ajouter mise à jour compteur :

```javascript
// À la fin de la fonction, avant le return
const countEl = document.getElementById('selectedClientsCount');
if (countEl) {
    countEl.textContent = selectedClients.length;
}
```

---

## E) RÉSUMÉ DES MODIFICATIONS

### Fichiers modifiés :
1. `public/maps.php` - HTML wrapper + nouvelles structures
2. `assets/css/maps.css` - Styles scoped sous `#maps-page`
3. `public/maps.php` - Scripts JS supplémentaires

### Nouvelles fonctionnalités ajoutées :
- ✅ Wrapper scoped `#maps-page`
- ✅ Toasts/notifications
- ✅ localStorage persistence
- ✅ Filtres visuels (SAV/Livraison/Normal)
- ✅ Recherche dans zone visible
- ✅ Export CSV
- ✅ Sections repliables
- ✅ Barre progression géocodage
- ✅ Compteur clients sélectionnés
- ✅ Légende markers
- ✅ Améliorations responsive
- ✅ Accessibilité (focus, ARIA)

### À faire ensuite (optionnel) :
- Clustering markers (nécessite Leaflet.markercluster CDN)
- Drag & drop clients (nécessite bibliothèque)
- Export PDF (nécessite bibliothèque)

---

**Fin du plan d'amélioration**
