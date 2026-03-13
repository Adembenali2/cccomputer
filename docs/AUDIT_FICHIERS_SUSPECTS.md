# Audit ciblé des fichiers suspects

**Date :** 6 mars 2025  
**Objectif :** Vérification précise avant toute suppression. Aucune modification effectuée.

---

## 1. API/osrm_route.php

### 1.1 Références directes

| Type | Résultat |
|------|----------|
| **include / require** | Aucun |
| **fetch / ajax / form action** | Aucun |
| **lien HTML** | Aucun |
| **redirection** | Aucune |
| **script cron** | Aucun |

### 1.2 Usage indirect

- **Aucun.** Le fichier est un proxy OSRM prévu pour contourner les problèmes CORS, mais `maps.php` appelle directement `https://router.project-osrm.org/route/v1/driving/...` (L.1778).

### 1.3 Preuve de non-usage

```
public/maps.php L.1778:
    const url = `https://router.project-osrm.org/route/v1/driving/${coordStr}?overview=full&geometries=geojson&steps=true`;
```

- Aucune occurrence de `osrm_route`, `/API/osrm`, ou URL vers ce fichier dans le code applicatif.
- `API/osrm_route.php` L.5-6 : commentaire explicite « maps.php appelle OSRM directement (router.project-osrm.org), pas ce proxy ».
- `docs/AUDIT_TECHNIQUE_MAPS.md` L.314 : « Aucun fichier. maps.php appelle router.project-osrm.org directement. »

### 1.4 Niveau de sécurité de suppression

**Probablement supprimable**

### 1.5 Recommandation

Supprimer après vérification manuelle. Conserver uniquement si vous prévoyez de centraliser les appels OSRM via ce proxy (CORS, auth, rate-limit).

---

## 2. API/chatroom_search_users.php

### 2.1 Références directes

| Type | Résultat |
|------|----------|
| **include / require** | Aucun |
| **fetch / ajax / form action** | Aucun |
| **lien HTML** | Aucun |
| **redirection** | Aucune |
| **script cron** | Aucun |

### 2.2 Usage indirect

- API prévue pour les mentions `@username` dans la messagerie.
- `messagerie.php` utilise `private_messages_list_users.php` pour la recherche d’utilisateurs (L.512), pas `chatroom_search_users.php`.

### 2.3 Preuve de non-usage

```
public/messagerie.php L.512:
    const url = `/API/private_messages_list_users.php?q=${encodeURIComponent(query)}&limit=100`;
```

- Aucun `fetch` vers `chatroom_search_users.php` dans `messagerie.php`.
- `docs/RAPPORT_MESSAGERIE.md` L.307-310 : « L'API chatroom_search_users.php existe mais n'est pas appelée par messagerie.php ».

### 2.4 Niveau de sécurité de suppression

**Probablement supprimable**

### 2.5 Recommandation

Supprimer si les mentions @ ne sont pas utilisées. Sinon, brancher l’appel dans `messagerie.php` avant suppression.

---

## 3. API/auth_status.php

### 3.1 Références directes

| Type | Résultat |
|------|----------|
| **include / require** | Aucun (sauf son propre `api_helpers.php`) |
| **fetch / ajax / form action** | Aucun |
| **lien HTML** | Aucun |
| **redirection** | Aucune |
| **script cron** | Aucun |

### 3.2 Usage indirect

- Endpoint prévu pour vérifier le statut d’authentification (éviter du polling inutile).
- Aucune référence à `auth_status` dans le code PHP, JS ou HTML.

### 3.3 Preuve de non-usage

- `grep "auth_status"` : seules occurrences dans `auth_status.php` lui-même, `AUDIT_NETTOYAGE_PROJET.md` et `CORRECTIONS_SESSION_RAILWAY.md`.
- Aucun `fetch('/API/auth_status.php')` dans le projet.

### 3.4 Niveau de sécurité de suppression

**Probablement supprimable**

### 3.5 Recommandation

Supprimer. Endpoint jamais utilisé. Peut être recréé si besoin futur de vérification d’auth côté client.

---

## 4. public/ajax/paper_move.php

### 4.1 Références directes

| Type | Résultat |
|------|----------|
| **include / require** | Aucun (sauf auth, helpers) |
| **fetch / ajax / form action** | Aucun |
| **lien HTML** | Aucun |
| **redirection** | Aucune |
| **script cron** | Aucun |

### 4.2 Usage indirect

- Alternative à `API/stock_move.php` pour les mouvements papier (entrée/sortie/ajustement).
- `stock.php` utilise uniquement `API/stock_move.php` (L.1943, L.1985).

### 4.3 Preuve de non-usage

```
public/stock.php L.1943:
    fetch('/API/stock_move.php?type=' + encodeURIComponent(apiType) + '&id=' + encodeURIComponent(productId), {

public/stock.php L.1985:
    fetch('/API/stock_move.php', {
```

- Aucune référence à `paper_move`, `/ajax/paper_move` ou `/public/ajax/paper_move` dans le code applicatif.
- `docs/UI_SPEC_STOCK.md` L.321 : mentionne « paper_move.php ou API/stock_move.php » comme options, mais l’implémentation actuelle n’utilise que `stock_move.php`.

### 4.4 Niveau de sécurité de suppression

**Probablement supprimable**

### 4.5 Recommandation

Supprimer après vérification que `API/stock_move.php` gère bien tous les types de mouvements papier (type `papier` dans `$typeMap` L.23). Les deux scripts écrivent dans `paper_moves` ; `stock_move.php` couvre ce cas.

---

## 5. includes/debug_helpers.php

### 5.1 Références directes

| Type | Résultat |
|------|----------|
| **include / require** | Aucun |
| **fetch / ajax / form action** | N/A |
| **lien HTML** | N/A |
| **redirection** | N/A |
| **script cron** | Aucun |

### 5.2 Usage indirect

- Définit `debugLog()` pour scripts d’import et de diagnostic.
- Aucun `require` ou `include` de `debug_helpers.php` dans le projet.
- Aucun appel à `debugLog()` trouvé dans le code.

### 5.3 Preuve de non-usage

```
grep "debug_helpers|debugLog" :
- includes/debug_helpers.php : définition du fichier et de la fonction
- docs/AUDIT_NETTOYAGE_PROJET.md : mention dans l’audit
```

- Aucun fichier PHP ne charge `debug_helpers.php`.
- Aucun fichier n’appelle `debugLog()`.

### 5.4 Niveau de sécurité de suppression

**Sûr à supprimer**

### 5.5 Recommandation

Supprimer. Fichier orphelin, jamais inclus ni utilisé.

---

## 6. includes/db.php

### 6.1 Références directes

| Type | Résultat |
|------|----------|
| **include / require** | 12 fichiers |
| **fetch / ajax / form action** | N/A |
| **lien HTML** | N/A |
| **redirection** | N/A |
| **script cron** | N/A |

### 6.2 Fichiers qui requièrent db.php

| Fichier | Contexte |
|---------|----------|
| `API/maps_geocode.php` | L.27 |
| `API/messagerie_mark_read.php` | L.19 |
| `API/messagerie_get_first_livraisons.php` | L.17 |
| `API/messagerie_get_first_sav.php` | L.17 |
| `API/messagerie_send.php` | L.20 |
| `API/messagerie_delete.php` | L.18 |
| `API/messagerie_reply.php` | L.68 |
| `API/clients/get_client_photocopieur.php` | L.5 (après auth.php qui définit $pdo) |
| `API/clients/attribuer_photocopieur.php` | L.5 (après auth.php) |
| `sql/run_migration_chatroom_image_path.php` | L.7 |
| `sql/run_migration_client_geocode.php` | L.7 |
| `sql/run_migration_client_stock.php` | L.7 |
| `sql/run_migration_sav.php` | L.7 |
| `sql/run_migration_last_activity.php` | L.7 |
| `sql/run_migration_user_permissions.php` | L.7 |

### 6.3 Usage indirect

- `db.php` définit uniquement la constante `DB_LOADED` (L.15-16). Il ne crée plus `$pdo`.
- Les fichiers qui chargent `auth.php` avant `db.php` obtiennent `$pdo` via `auth.php` (L.7 : `$pdo = getPdo()`).
- Les fichiers qui chargent seulement `session_config` + `db.php` (ex. `messagerie_mark_read.php`) utilisent `$pdo` sans qu’il soit défini par `db.php` → risque d’erreur si `db.php` n’a pas été modifié localement pour fournir `$pdo`.
- `maps_geocode.php` charge `db.php` mais n’utilise pas `$pdo` (géocodage via curl + cache fichier).

### 6.4 Niveau de sécurité de suppression

**À migrer avant suppression**

### 6.5 Recommandation

Ne pas supprimer tant que la migration n’est pas faite. Pour chaque fichier listé :

1. Remplacer `require_once ... db.php` par `require_once ... helpers.php` (ou `api_helpers.php` selon le cas).
2. Ajouter `$pdo = getPdo();` après les includes si le fichier utilise `$pdo`.
3. Supprimer `db.php` une fois tous les usages migrés.

---

## Tableau récapitulatif

| Fichier | Statut | Preuve d’usage / non-usage | Recommandation |
|---------|--------|----------------------------|----------------|
| **API/osrm_route.php** | Non utilisé | `maps.php` L.1778 appelle `router.project-osrm.org` directement ; aucun fetch vers cet endpoint | Probablement supprimable |
| **API/chatroom_search_users.php** | Non utilisé | `messagerie.php` L.512 utilise `private_messages_list_users.php` ; aucun fetch vers cet endpoint | Probablement supprimable |
| **API/auth_status.php** | Non utilisé | Aucune référence dans le code applicatif | Probablement supprimable |
| **public/ajax/paper_move.php** | Non utilisé | `stock.php` L.1943 et L.1985 utilisent `API/stock_move.php` ; aucun appel à paper_move | Probablement supprimable |
| **includes/debug_helpers.php** | Non utilisé | Aucun `require` ; aucun appel à `debugLog()` | Sûr à supprimer |
| **includes/db.php** | Utilisé | 12+ fichiers font `require_once ... db.php` | À migrer avant suppression |

---

## Ordre de suppression recommandé

1. **Sûr immédiatement :** `includes/debug_helpers.php`
2. **Après vérification manuelle :** `API/osrm_route.php`, `API/chatroom_search_users.php`, `API/auth_status.php`, `public/ajax/paper_move.php`
3. **Après migration :** `includes/db.php` (remplacer tous les `require db.php` + `$pdo` par `getPdo()`)

---

*Audit réalisé sans modification du code. Aucune suppression effectuée.*
