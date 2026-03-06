# Audit de nettoyage du projet CCComputer

**Date :** 6 mars 2025  
**Objectif :** Identifier les fichiers inutilisés ou redondants sans supprimer quoi que ce soit.

---

## A. Arborescence actuelle simplifiée

```
cccomputer-1/
├── .dockerignore, .gitignore
├── Caddyfile, Dockerfile
├── composer.json, composer.lock
├── health.php, index.php, router.php
├── railway.json
├── cleanup_project.ps1, cleanup_project_v2.ps1, cleanup_project_v3.ps1
├── cleanup_report.md
├── AMELIORATIONS_MAPS.md, AUDIT_RAPPORT.md, CHECKLIST_TESTS.md
├── RAPPORT_MESSAGERIE.md
├── maps-enhancements.js          ← DOUBLON (racine)
├── PATCH_3_JS_NEW_FUNCTIONS.js  ← Patch source
│
├── API/                          (67 fichiers)
│   ├── _bootstrap.php
│   ├── auth_status.php
│   ├── profil_search_users.php
│   ├── chatroom_*.php (8)
│   ├── dashboard_*.php (7)
│   ├── factures_*.php (8)
│   ├── messagerie_*.php (8)
│   ├── maps_*.php (6)
│   ├── paiements_*.php (6)
│   ├── private_messages_*.php (3)
│   ├── stock_*.php, get_product_by_barcode.php
│   ├── user_online_status.php
│   ├── historique_export.php
│   ├── osrm_route.php
│   ├── clients/ (2)
│   └── import/ (5)
│
├── app/
│   ├── Models/ (3)
│   ├── Repositories/ (2)
│   └── Services/ (2)
│
├── assets/
│   ├── css/ (14 fichiers)
│   ├── js/ (4 fichiers)
│   └── logos/ (logo.png, logo1.png)
│
├── config/
│   ├── app.php
│   └── sentry.php
│
├── docs/                         (12 fichiers)
│
├── includes/                     (15 fichiers)
│   ├── api_helpers.php, auth.php, auth_role.php
│   ├── CacheHelper.php, db.php, db_connection.php
│   ├── debug_helpers.php
│   ├── ErrorHandler.php, helpers.php, historique.php
│   ├── Logger.php, logout.php, messagerie_purge.php
│   ├── rate_limiter.php, security_headers.php
│   ├── session_config.php, Validator.php
│
├── public/
│   ├── agenda.php, clients.php, client_fiche.php
│   ├── dashboard.php, historique.php, livraison.php
│   ├── login.php, maps.php, messagerie.php
│   ├── paiements.php, profil.php, sav.php
│   ├── stock.php, photocopieurs_details.php
│   ├── scan_barcode.php, print_labels.php, view_facture.php
│   ├── test_smtp.php, ping.txt
│   ├── stock_ui_mockup.html
│   ├── ajax/paper_move.php
│   └── API/test_smtp.php
│
├── redirection/                  (4 fichiers)
├── scripts/                      (10 fichiers)
├── source/
│   ├── connexion/login_process.php
│   └── templates/header.php
│
├── sql/                          (20+ fichiers)
├── src/
│   ├── Mail/ (4)
│   └── Services/ (2)
│
├── uploads/chatroom/.htaccess, uploads/qrcodes/.htaccess
│
├── _cleanup_backup_20251226_141332/   ← Backup partiel
├── _cleanup_backup_20251226_142942/   ← Backup volumineux (36+ fichiers)
└── vendor/                       (Composer, ne pas toucher)
```

---

## B. Fichiers probablement inutiles (catégorie D)

### D1 — Très probablement inutiles (suppression recommandée après vérification manuelle)

| Fichier | Raison |
|---------|--------|
| `maps-enhancements.js` (racine) | Doublon de `assets/js/maps-enhancements.js`. Seul `assets/js/` est chargé par maps.php. |
| `PATCH_3_JS_NEW_FUNCTIONS.js` | Fichier patch source ; le contenu a été intégré dans `assets/js/maps-enhancements.js`. |
| `public/stock_ui_mockup.html` | Mockup HTML de démo, non lié au flux applicatif. |
| `_cleanup_backup_20251226_141332/` | Backup partiel (cache/.gitignore). |
| `_cleanup_backup_20251226_142942/` | Backup volumineux de nettoyage précédent (36+ fichiers .md). |
| `cleanup_project.ps1`, `cleanup_project_v2.ps1`, `cleanup_project_v3.ps1` | Scripts de nettoyage ; à archiver ou supprimer si plus utilisés. |
| `cleanup_report.md` | Rapport de nettoyage précédent. |

### D2 — Fichiers de test / debug (à garder en dev, supprimer en prod)

| Fichier | Raison |
|---------|--------|
| `public/test_smtp.php` | Test SMTP ; utile en dev, à protéger/supprimer en prod. |
| `public/API/test_smtp.php` | Idem. |
| `public/ping.txt` | Health check ; conserver si utilisé par un monitoring. |

---

## C. Fichiers à vérifier avant suppression (catégorie C)

### C1 — APIs jamais ou rarement appelées

| Fichier | Statut | Référence |
|---------|--------|-----------|
| `API/osrm_route.php` | Jamais appelé par maps.php (appel direct à OSRM) | docs/AUDIT_TECHNIQUE_MAPS.md |
| `API/chatroom_search_users.php` | Non utilisé par messagerie.php | docs/RAPPORT_MESSAGERIE.md |
| `API/auth_status.php` | Aucune référence fetch/require trouvée | — |
| `API/import/check_log.php` | Usage non identifié (cron ?) | — |
| `public/ajax/paper_move.php` | Jamais appelé par stock.php (utilise API/stock_move.php) | docs/UI_SPEC_STOCK.md |

### C2 — Fichiers potentiellement obsolètes

| Fichier | Raison |
|---------|--------|
| `includes/debug_helpers.php` | Aucun `require` trouvé dans le projet. |
| `includes/db.php` | Déprécié, mais encore requis par ~10 fichiers (messagerie_*, migrations, etc.). |
| `API/generate_facture_pdf.php` | **N'existe pas** — profil.php L.2446 pointe vers ce fichier (lien cassé). Corriger vers `/public/view_facture.php?id=X`. |

### C3 — Page manquante (lien cassé)

| Référence | Fichier | Problème |
|-----------|---------|----------|
| `header.php` L.89 | `/public/commercial.php` | **Fichier inexistant** — lien 404 pour les utilisateurs "Chargé relation clients" et Admin. |

### C4 — Assets manquants

| Référence | Fichier | Problème |
|-----------|---------|----------|
| `stock.php` L.339-343 | `/assets/img/stock/*.jpg` | Dossier `assets/img/` absent — images (photocopieurs, lcd, pc, toners, papier) non trouvées. |

---

## D. Fichiers sûrs à supprimer (après validation manuelle)

Ces fichiers peuvent être supprimés en toute sécurité **une fois la vérification manuelle effectuée** :

1. **Doublons JS**
   - `maps-enhancements.js` (racine) — doublon de `assets/js/maps-enhancements.js`
   - `PATCH_3_JS_NEW_FUNCTIONS.js` — patch source intégré

2. **Backups de nettoyage**
   - `_cleanup_backup_20251226_141332/`
   - `_cleanup_backup_20251226_142942/`

3. **Mockup**
   - `public/stock_ui_mockup.html`

4. **Scripts de nettoyage obsolètes**
   - `cleanup_project.ps1`, `cleanup_project_v2.ps1`, `cleanup_project_v3.ps1`
   - `cleanup_report.md`

---

## E. Fichiers sensibles — NE PAS SUPPRIMER sans vérification

| Fichier / Dossier | Rôle |
|-------------------|------|
| `includes/*` | Auth, helpers, DB, sécurité, session |
| `config/*` | Configuration app |
| `source/templates/header.php` | Navigation globale |
| `.htaccess` | Règles Apache |
| `uploads/*` | Fichiers utilisateurs |
| `cache/*` | Cache généré (roles_enum.json, etc.) |
| `vendor/` | Dépendances Composer |

---

## F. Cas particuliers identifiés

### F1 — JS non importés
- `maps-enhancements.js` (racine) : non chargé (doublon).
- `assets/js/maps-enhancements.js` : chargé par `maps.php` ✓
- `assets/js/api.js` : chargé par `dashboard.php`, `messagerie.php` ✓
- `assets/js/clients.js` : chargé par `clients.php` ✓
- `assets/js/dashboard.js` : chargé par `dashboard.php` ✓

### F2 — CSS jamais chargés
- Tous les CSS dans `assets/css/` sont référencés par au moins une page PHP.

### F3 — APIs jamais appelées
- `API/osrm_route.php` — non utilisé
- `API/chatroom_search_users.php` — non utilisé
- `API/auth_status.php` — non utilisé

### F4 — Scripts de migration / debug
- `scripts/analyze_sql_performance.php` — script d’analyse
- `scripts/monitor_corrections.php` — monitoring
- `scripts/validate_corrections.php` — validation
- À conserver si utilisés en maintenance.

### F5 — Docs potentiellement obsolètes
- Contenu dans `_cleanup_backup_*` : archives de docs déplacées.
- `docs/` à la racine : à conserver (audits, rapports).

### F6 — Lien PDF facture cassé
- `profil.php` L.2446 : `href="/API/generate_facture_pdf.php?id=..."` → fichier inexistant.
- **Correction :** remplacer par `href="/public/view_facture.php?id=..."`.

---

## G. Plan de nettoyage progressif

### Phase 1 — Corrections (sans suppression)
1. Corriger le lien PDF dans `profil.php` : `generate_facture_pdf.php` → `view_facture.php`
2. Créer `public/commercial.php` ou retirer le lien du header
3. Créer `assets/img/stock/` et y placer les images, ou retirer les références dans `stock.php`

### Phase 2 — Suppressions à faible risque
1. Supprimer `maps-enhancements.js` (racine)
2. Supprimer `PATCH_3_JS_NEW_FUNCTIONS.js`
3. Supprimer `public/stock_ui_mockup.html`

### Phase 3 — Backups
1. Archiver `_cleanup_backup_20251226_141332/` et `_cleanup_backup_20251226_142942/` hors du projet (zip, autre disque)
2. Supprimer les dossiers du dépôt

### Phase 4 — Scripts
1. Archiver ou supprimer `cleanup_project*.ps1` et `cleanup_report.md` si plus utilisés

### Phase 5 — APIs inutilisées (optionnel)
1. Après tests complets : supprimer `API/osrm_route.php`, `API/chatroom_search_users.php`, `API/auth_status.php` si confirmé inutiles
2. Supprimer `public/ajax/paper_move.php` si `API/stock_move.php` couvre tous les cas

---

## H. Commandes de suppression (à exécuter manuellement)

```powershell
# Phase 2 - Doublons et mockup
Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\maps-enhancements.js" -ErrorAction SilentlyContinue
Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\PATCH_3_JS_NEW_FUNCTIONS.js" -ErrorAction SilentlyContinue
Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\public\stock_ui_mockup.html" -ErrorAction SilentlyContinue

# Phase 3 - Backups (APRÈS archivage manuel)
# Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\_cleanup_backup_20251226_141332" -Recurse -Force
# Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\_cleanup_backup_20251226_142942" -Recurse -Force

# Phase 4 - Scripts de nettoyage
# Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\cleanup_project.ps1" -ErrorAction SilentlyContinue
# Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\cleanup_project_v2.ps1" -ErrorAction SilentlyContinue
# Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\cleanup_project_v3.ps1" -ErrorAction SilentlyContinue
# Remove-Item "c:\Users\USER33\Desktop\cccomputer-1\cleanup_report.md" -ErrorAction SilentlyContinue
```

---

## I. Ordre recommandé de suppression

1. **Sûr immédiatement :** `maps-enhancements.js` (racine), `PATCH_3_JS_NEW_FUNCTIONS.js`
2. **Sûr après vérification :** `stock_ui_mockup.html`
3. **Après archivage :** dossiers `_cleanup_backup_*`
4. **Optionnel :** scripts `cleanup_project*.ps1`, `cleanup_report.md`
5. **À ne pas supprimer sans migration :** `includes/db.php` (encore utilisé), `includes/debug_helpers.php` (vérifier usage)

---

## J. Résumé

| Catégorie | Nombre | Action |
|-----------|--------|--------|
| **A — Utilisé** | ~180 fichiers | Ne pas toucher |
| **B — Utilisé indirectement** | ~15 fichiers | Vérifier avant toute modification |
| **C — Potentiellement inutilisé** | ~10 fichiers | Vérifier manuellement |
| **D — Très probablement inutile** | ~12 fichiers/dossiers | Supprimer après validation |

**Gain estimé :** ~5–10 Mo (principalement `_cleanup_backup_*` et doublons).

---

*Rapport généré par audit de nettoyage — aucune suppression automatique effectuée.*
