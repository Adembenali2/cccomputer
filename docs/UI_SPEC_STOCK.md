# UI Spec — Page Stock (refonte pro)

## 1. Vue d'ensemble

Structure en 3 blocs :
- **A) Header sticky** : titre, recherche, actions (Ajouter, Export, Scan)
- **B) Tabs** : Photocopieurs | Papier | Toners | LCD | PC
- **C) Contenu tab** : KPI row + Table + Drawer (side panel)

---

## 2. Structure HTML

```
.stock-page
├── .stock-header (sticky)
│   ├── .stock-header-title
│   ├── .stock-header-search
│   └── .stock-header-actions
│       ├── .btn-add
│       ├── .btn-export
│       └── .btn-scan
│
├── .stock-tabs
│   └── [role="tablist"]
│       ├── [role="tab"]#tab-photocopieurs
│       ├── [role="tab"]#tab-papier
│       ├── [role="tab"]#tab-toners
│       ├── [role="tab"]#tab-lcd
│       └── [role="tab"]#tab-pc
│
├── .stock-tabpanels
│   └── [role="tabpanel"]#panel-*
│       ├── .stock-kpi-row
│       │   ├── .kpi-card (stock total)
│       │   ├── .kpi-card (stock faible)
│       │   ├── .kpi-card (entrées 30j)
│       │   └── .kpi-card (sorties 30j)
│       │
│       └── .stock-table-wrapper
│           └── .tbl-stock
│
└── .stock-drawer (side panel)
    ├── .stock-drawer-backdrop
    ├── .stock-drawer-panel
    │   ├── .stock-drawer-header
    │   ├── .stock-drawer-tabs
    │   │   ├── [tab] Détails
    │   │   ├── [tab] Mouvements
    │   │   ├── [tab] Actions
    │   │   └── [tab] Étiquettes
    │   └── .stock-drawer-content
    │       └── [tabpanel] contenu
    └── .stock-drawer-close
```

---

## 3. Composants

### 3.1 Header sticky

| Élément | Classe / ID | Description |
|---------|-------------|-------------|
| Conteneur | `.stock-header` | `position: sticky; top: 0; z-index: 100` |
| Titre | `.stock-header-title` | H1 |
| Recherche | `#search-input` | Input plein largeur, placeholder "Rechercher..." |
| Actions | `.stock-header-actions` | Flex gap, boutons alignés |

### 3.2 Tabs

| Élément | Attributs | Rôle |
|--------|-----------|------|
| `tablist` | `role="tablist"` | Conteneur des onglets |
| `tab` | `role="tab"`, `aria-selected`, `aria-controls`, `id` | Onglet |
| `tabpanel` | `role="tabpanel"`, `aria-labelledby`, `hidden` | Contenu |

**Comportement** : clic sur tab → afficher panel correspondant, masquer les autres.

### 3.3 KPI row

| KPI | Classe | Valeur |
|-----|--------|--------|
| Stock total | `.kpi-card[data-kpi="total"]` | Somme des quantités |
| Stock faible | `.kpi-card[data-kpi="low"]` | Nombre items sous seuil |
| Entrées 30j | `.kpi-card[data-kpi="in"]` | Mouvements positifs |
| Sorties 30j | `.kpi-card[data-kpi="out"]` | Mouvements négatifs |

### 3.4 Table

| Colonne | Tri | Badge |
|---------|-----|-------|
| Qté | Oui | `.badge-stock` (vert si OK, orange si faible, rouge si 0) |
| Modèle | Oui | — |
| Poids / Couleur / État | Oui | — |

### 3.5 Drawer (side panel)

| Élément | Propriété |
|--------|-----------|
| Largeur | 400px (desktop), 100% (mobile) |
| Position | Droite, slide-in |
| Animation | `transform: translateX(100%)` → `translateX(0)` |
| Backdrop | `opacity: 0.5`, clic = fermer |

**Onglets drawer** :
- **Détails** : infos produit (lecture seule)
- **Mouvements** : liste des 20 derniers mouvements
- **Actions** : formulaire Entrée/Sortie/Ajustement
- **Étiquettes** : bouton Imprimer étiquettes

### 3.6 Toasts

| Type | Classe | Couleur |
|------|--------|---------|
| Succès | `.toast--success` | Vert |
| Erreur | `.toast--error` | Rouge |
| Info | `.toast--info` | Bleu |

**Position** : `position: fixed; top: 1rem; right: 1rem; z-index: 1000`  
**Conteneur** : `#toast-container`  
**Auto-dismiss** : 4–5 s

### 3.7 Loader

| Élément | Classe | Usage |
|--------|--------|-------|
| Overlay | `.loader-overlay` | Couvre zone ou modale |
| Spinner | `.loader-spinner` | Animation rotation |
| Texte | `.loader-text` | "Chargement..." |

---

## 4. Variables CSS (stock-ui-pro)

```css
/* Stock UI Pro — variables additionnelles */
:root {
  --stock-drawer-width: 400px;
  --stock-drawer-width-mobile: 100%;
  --stock-header-height: 64px;
  --stock-tab-height: 48px;
  --stock-kpi-gap: 1rem;
  --toast-width: 320px;
  --toast-duration: 4s;
  --transition-drawer: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  --color-success: #059669;
  --color-error: #dc2626;
  --color-warning: #d97706;
  --color-info: #0284c7;
}
```

---

## 5. Styles principaux

### 5.1 Header

```css
.stock-header {
  position: sticky;
  top: 0;
  z-index: 100;
  background: var(--bg-primary);
  border-bottom: 1px solid var(--border-color);
  padding: 1rem 1.5rem;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
  box-shadow: var(--shadow-sm);
}
```

### 5.2 Tabs

```css
.stock-tabs [role="tablist"] {
  display: flex;
  gap: 0.25rem;
  border-bottom: 2px solid var(--border-color);
  padding: 0 0.5rem;
}
.stock-tabs [role="tab"] {
  padding: 0.75rem 1.25rem;
  border: none;
  background: transparent;
  cursor: pointer;
  font-weight: 600;
  color: var(--text-secondary);
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
}
.stock-tabs [role="tab"][aria-selected="true"] {
  color: var(--accent-primary);
  border-bottom-color: var(--accent-primary);
}
```

### 5.3 Drawer

```css
.stock-drawer {
  position: fixed;
  inset: 0;
  z-index: 1000;
  pointer-events: none;
}
.stock-drawer.is-open {
  pointer-events: auto;
}
.stock-drawer-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,0.4);
  opacity: 0;
  transition: opacity var(--transition-drawer);
}
.stock-drawer.is-open .stock-drawer-backdrop {
  opacity: 1;
}
.stock-drawer-panel {
  position: absolute;
  top: 0;
  right: 0;
  width: var(--stock-drawer-width);
  height: 100%;
  background: var(--bg-primary);
  box-shadow: -4px 0 24px rgba(0,0,0,0.15);
  transform: translateX(100%);
  transition: transform var(--transition-drawer);
  overflow-y: auto;
}
.stock-drawer.is-open .stock-drawer-panel {
  transform: translateX(0);
}
```

### 5.4 Toasts

```css
#toast-container {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 1001;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: var(--toast-width);
}
.toast {
  padding: 0.875rem 1.25rem;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-lg);
  animation: toast-in 0.3s ease;
}
.toast--success { background: var(--color-success); color: white; }
.toast--error { background: var(--color-error); color: white; }
.toast--info { background: var(--color-info); color: white; }
@keyframes toast-in {
  from { opacity: 0; transform: translateX(100%); }
  to { opacity: 1; transform: translateX(0); }
}
```

---

## 6. Accessibilité

| Élément | Règle |
|--------|-------|
| Tabs | `role="tablist"`, `tabindex="0"`, `aria-selected`, `aria-controls`, `aria-labelledby` |
| Drawer | `aria-modal="true"`, `aria-labelledby`, focus trap |
| Toasts | `role="status"`, `aria-live="polite"` |
| Loader | `role="status"`, `aria-busy="true"` |
| Focus | Fermer drawer = focus sur ligne cliquée |

---

## 7. Responsive

| Breakpoint | Comportement |
|------------|--------------|
| < 768px | Drawer 100% largeur, tabs scroll horizontal |
| 768–1024px | Drawer 100% ou 90% |
| > 1024px | Drawer 400px |

---

## 8. IDs existants à conserver

| ID | Usage |
|----|-------|
| `#q` | Recherche (ou `#search-input`) |
| `#addForm`, `#addError`, `#addSuccess`, `#addSubmit` | Modale ajout |
| `#detailModal`, `#detailGrid`, `#modalTitle` | Modale détail (→ drawer) |
| `#stockMasonry` | Contenu principal |

---

## 9. Mapping ancien → nouveau

| Ancien | Nouveau |
|--------|---------|
| 5 sections empilées | 5 tabs |
| Modale détail | Drawer |
| Modale ajout | Inchangée (ou intégrée dans drawer) |
| Message flash GET | Toast |
| `#addError` inline | Toast + zone erreur |

---

## 10. Roadmap de commits (petits)

| # | Commit | Description |
|---|--------|--------------|
| 1 | `Stock UI: tabs + drawer structure` | Ajouter stock-ui-pro.css, structure HTML tabs + drawer, sans casser l'existant (feature flag ou route parallèle) |
| 2 | `Stock UI: toasts + loaders` | Système toast global, loader sur fetch, réutiliser dans modale ajout |
| 3 | `Stock UI: mouvements papier intégrés` | Formulaire Entrée/Sortie dans drawer, appel paper_move.php ou API/stock_move.php |
| 4 | `Stock UI: historique mouvements` | Afficher derniers 20 mouvements dans onglet Drawer (API à créer si besoin) |

---

## 11. Checklist tests

| # | Scénario | Attendu |
|---|----------|---------|
| 1 | Clic onglet Papier | Panel Papier visible, autres masqués |
| 2 | Clic ligne tableau | Drawer s'ouvre à droite |
| 3 | Clic backdrop drawer | Drawer se ferme |
| 4 | Clic × drawer | Drawer se ferme |
| 5 | Clic onglet Drawer (Mouvements) | Contenu Mouvements affiché |
| 6 | Bouton Ajouter → toast | Toast succès affiché 4s |
| 7 | Focus clavier tabs | Tab + flèches pour naviguer |
| 8 | Responsive < 768px | Drawer pleine largeur |
