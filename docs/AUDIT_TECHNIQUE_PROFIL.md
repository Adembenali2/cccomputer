# Audit technique – Page Profil

## 1. Arborescence des fichiers impliqués

```
public/profil.php                    # Page principale (~2850 lignes)
├── includes/security_headers.php    # Headers de sécurité (avant output)
├── includes/auth_role.php           # authorize_page('profil', [...])
├── includes/helpers.php             # getPdo, h, validateEmail, validateString, validatePhone, validateId, safeFetch, safeFetchAll
├── includes/historique.php          # enregistrerAction (audit)
├── source/templates/header.php      # Navigation, logo, menu
├── assets/css/main.css              # Styles globaux
├── assets/css/profil.css            # Styles spécifiques profil
└── API/profil_search_users.php      # Recherche AJAX utilisateurs

cache/roles_enum.json                # Cache des rôles (généré par getAvailableRoles)
```

### Détail par fichier

| Fichier | Rôle | Criticité | Éléments importants |
|---------|------|-----------|---------------------|
| `public/profil.php` | Page principale : CRUD utilisateurs, permissions, SAV, paiements, factures | **Critique** | POST handlers (create, update, toggle, resetpwd, save_permissions), requêtes SQL, formulaires, JS inline |
| `source/templates/header.php` | En-tête commun, navigation | Secondaire | Lien vers profil, menu |
| `assets/css/main.css` | Variables CSS, layout global | Secondaire | Variables --accent-primary, etc. |
| `assets/css/profil.css` | Styles profil (meta-cards, filtres, tableaux, permissions) | Secondaire | .profil-meta, .filter-bar, .users-table, .permission-category |
| `API/profil_search_users.php` | Recherche utilisateurs en temps réel | **Critique** | GET ?q=, retourne JSON {ok, users, count} |
| `includes/auth_role.php` | Vérification accès page | **Critique** | authorize_page('profil', ['Admin','Dirigeant','Technicien','Livreur']) |
| `includes/helpers.php` | Fonctions utilitaires | **Critique** | validateEmail, validateString, validatePhone, validateId, safeFetch, safeFetchAll |
| `includes/historique.php` | Audit des actions | Secondaire | enregistrerAction($pdo, $userId, $action, $details) |
| `includes/security_headers.php` | Headers X-Frame-Options, etc. | Secondaire | Appelé avant tout output |

---

## 2. Fonctionnement général

### Construction de la page

1. **Sécurité** : `security_headers.php` → `auth_role` → `helpers`
2. **Contexte** : `$currentUser`, `$isLivreur`, `$isTechnicien`, `$isAdminOrDirigeant`, `$hasRestrictions`
3. **POST** : Si `$_SERVER['REQUEST_METHOD'] === 'POST'` → traitement des actions (create, update, toggle, resetpwd, save_permissions) → redirection PRG
4. **GET** : Chargement des données (utilisateurs, stats, SAV, paiements, factures, permissions)
5. **Rendu** : HTML avec formulaires, tableaux, panneaux

### Données affichées

| Donnée | Source | Requête / Méthode |
|--------|--------|-------------------|
| Liste utilisateurs | `utilisateurs` | SELECT avec filtre recherche (nom, prénom, email, rôle, statut) |
| Stats (total, actifs, inactifs) | `utilisateurs` | COUNT, SUM(CASE WHEN statut...) |
| Utilisateur connecté | `utilisateurs` | WHERE id = currentUserId() |
| Utilisateurs en ligne | `utilisateurs` | last_activity >= NOW() - 5 min |
| SAV | `sav` + `clients` | JOIN, filtre q_sav sur raison_sociale, nom_dirigeant, prenom_dirigeant |
| Paiements | `paiements` + `clients` + `factures` | JOIN, LIMIT 100 |
| Factures | `factures` + `clients` | JOIN, LIMIT 100 |
| Permissions | `user_permissions` | WHERE user_id = ? |

### Mise à jour des données

- **POST classique** : tous les formulaires envoient en POST vers `/public/profil.php`
- **Pattern PRG** : après traitement → `header('Location: ...')` + `exit`
- **Token CSRF** : vérifié sur chaque POST

### Formulaires

| Formulaire | Action POST | Champs principaux |
|------------|-------------|-------------------|
| Création utilisateur | `action=create` | Email, password, nom, prenom, telephone, Emploi, statut, date_debut |
| Modification utilisateur | `action=update` | id, Email, nom, prenom, telephone, Emploi, statut, date_debut |
| Toggle statut | `action=toggle` | id, to (actif/inactif) |
| Réinit mot de passe | `action=resetpwd` | id, new_password |
| Permissions | `action=save_permissions` | target_user_id, permissions[pageKey]=1 |
| Recherche utilisateurs | GET | q (recherche) |
| Recherche SAV | GET | q_sav |

### Upload / Avatar

- **Aucun upload d'image ou avatar** sur la page profil.

### Composants interactifs

- **Onglets / panneaux** : Créer utilisateur / Utilisateurs (boutons `.panel-toggle-btn`)
- **Sections** : SAV, Paiements, Factures (`.sav-panel`, `.payments-panel`, `.factures-panel`) – affichées au clic sur les quick-actions
- **Modales** : aucune
- **Recherche AJAX** : champ `#q` → fetch `/API/profil_search_users.php?q=` → mise à jour `#usersTableBody`

### Appels AJAX / fetch

- **Un seul** : recherche utilisateurs en temps réel
  - URL : `GET /API/profil_search_users.php?q=...`
  - Déclencheur : `input` sur `#q` (debounce 300 ms)
  - `credentials: 'include'` pour envoyer les cookies de session

---

## 3. Inventaire des fonctions et événements

### Fonctions PHP (profil.php)

| Fonction | Rôle | Appelée par | Sensibilité |
|----------|------|--------------|-------------|
| `getAvailableRoles($pdo)` | Récupère les rôles (enum Emploi) avec cache fichier | Début page | Faible |
| `sanitizeSearch($value)` | Nettoie la recherche (trim, mb_substr, 120 car) | Paramètre GET q | Faible |
| `logProfilAction($pdo, $userId, $action, $details)` | Log audit via enregistrerAction | Après chaque action POST | Faible |
| `validateUserData($data, $isUpdate)` | Valide email, nom, prénom, téléphone, date_debut, password | create, update | **Sensible** |
| `decode_msg($row)` | Décode JSON du champ msg | Non utilisée dans le code actuel ? | À vérifier |

### Fonctions JavaScript (inline profil.php)

| Fonction | Rôle | Éléments DOM | Endpoint |
|----------|------|--------------|----------|
| `updateTable(users)` | Met à jour le tableau utilisateurs | `#usersTableBody`, `#usersCount` | — |
| `escapeHtml(text)` | Échappe HTML | — | — |
| `performSearch(query)` | Recherche AJAX | `#searchLoading` | `/API/profil_search_users.php?q=` |
| `toggleClearButton(show)` | Affiche/masque le bouton clear | `#clearSearch` | — |
| `showPanel(targetId)` | Bascule panneaux Créer/Utilisateurs | `#createUserPanel`, `#usersPanel`, `.panel-toggle-btn` | — |
| `scrollToSection(event, sectionId)` | Scroll vers SAV/Paiements/Factures | `#sav`, `#paiements`, `#factures` | — |

### Événements

| Élément | Événement | Action |
|---------|-----------|--------|
| `#q` | `input` | Debounce 300 ms → performSearch |
| `#clearSearch` | `click` | Vide recherche, performSearch(''), focus |
| `#searchForm` | `submit` | preventDefault, performSearch |
| `.panel-toggle-btn` | `click` | showPanel(dataset.target) |
| `#selectAllPerms` | `click` | Coche toutes les permissions |
| `#deselectAllPerms` | `click` | Décoche toutes les permissions |
| `#perm_user_select` | `change` | Redirection `?perm_user=` + value |
| `.quick-action-btn` | `click` | scrollToSection (SAV, Paiements, Factures) |
| Formulaires POST | `submit` | Envoi classique vers profil.php |

---

## 4. Éléments DOM 

| id / classe | Rôle | Événements | Impact métier |
|-------------|------|------------|---------------|
| `#q` | Champ recherche utilisateurs | input, submit (form) | Filtrage liste, déclenche AJAX |
| `#searchForm` | Formulaire recherche | submit | preventDefault, lance recherche |
| `#searchLoading` | Spinner chargement | — | Affiché pendant fetch |
| `#clearSearch` | Bouton effacer recherche | click | Vide et relance recherche |
| `#usersTableBody` | Corps du tableau utilisateurs | — | Rempli par PHP ou updateTable() |
| `#usersCount` | Nombre d'utilisateurs | — | Mis à jour par PHP ou updateTable() |
| `#createUserPanel` | Panneau création | — | Affiché/masqué par showPanel |
| `#usersPanel` | Panneau liste + édition | — | Contient liste et formulaire édition |
| `#editPanel` | Formulaire modification | — | Visible si `?edit=id` |
| `#permissionsPanel` | Gestion permissions ACL | — | Visible pour Admin/Dirigeant |
| `#permissionsForm` | Formulaire permissions | submit | POST save_permissions |
| `#perm_user_select` | Sélecteur utilisateur pour permissions | change | Redirection ?perm_user= |
| `#selectAllPerms` | Tout autoriser | click | Coche toutes les checkboxes |
| `#deselectAllPerms` | Tout interdire | click | Décoche toutes |
| `.panel-toggle-btn` | Boutons Créer / Utilisateurs | click | showPanel |
| `#sav` | Section SAV | — | scrollToSection |
| `#paiements` | Section paiements | — | scrollToSection |
| `#factures` | Section factures | — | scrollToSection |
| `#savSearchForm` | Recherche SAV | submit | GET avec q_sav |
| `#q_sav` | Champ recherche SAV | — | Filtre côté serveur |
| `#onlineCount` | Nombre en ligne | — | Affichage |
| `#onlineTooltip` | Tooltip utilisateurs en ligne | hover (CSS) | Liste des connectés |

---

## 5. Données et sécurité

### Données

- **Utilisateurs** : Email, nom, prénom, téléphone, Emploi, statut, date_debut, password
- **Permissions** : user_permissions (page, allowed) par utilisateur

### Validations

| Champ | Validation | Fichier |
|-------|------------|---------|
| Email | validateEmail() | helpers.php |
| Nom, prénom | validateString(1, 100) | helpers.php |
| Téléphone | validatePhone() (optionnel) | helpers.php |
| Date début | preg_match `/^\d{4}-\d{2}-\d{2}$/` | profil.php validateUserData |
| Mot de passe | min 8 caractères | profil.php |
| ID | validateId() (entier > 0) | helpers.php |
| Rôle | in_array($ROLES) | profil.php |
| Statut | actif ou inactif | profil.php |

### Protections

| Protection | Implémentation |
|------------|----------------|
| **Auth** | authorize_page('profil', ['Admin','Dirigeant','Technicien','Livreur']) |
| **XSS** | h() sur toutes les sorties HTML |
| **CSRF** | ensureCsrfToken(), verifyCsrfToken() sur chaque POST |
| **SQL** | Requêtes préparées (safeFetch, safeFetchAll, $pdo->prepare) |
| **Session** | $_SESSION['user_id'], vérification emploi |
| **Autorisation** | $hasRestrictions : Livreur/Technicien ne peuvent modifier que leur profil |
| **Headers** | security_headers.php |

### Mots de passe

- **Création** : `password_hash(..., PASSWORD_BCRYPT, ['cost' => 12])`
- **Réinitialisation** : même hash, pas de vérification de l'ancien mot de passe
- **Livreur** : peut réinitialiser uniquement son propre mot de passe

### Fichiers uploadés

- Aucun sur la page profil.

### Risques / incohérences

1. **API profil_search_users** : pas de vérification CSRF (GET, acceptable)
2. **Recherche SAV** : `q_sav` utilisé dans LIKE sans échappement des `%` et `_` – **corrigé** : `str_replace(['%','_'], ['\\%','\\_'], $savSearch)` (L.649)
3. **Log des changements** : `$detail` peut contenir des valeurs non échappées dans le message d'audit (stockage interne, pas d'affichage direct)
4. **decode_msg** : fonction définie mais usage non trouvé dans le flux principal

---

## 6. Zones d'extension

| Zone | Emplacement | Raison | Risque | Exemples de features |
|------|-------------|--------|--------|----------------------|
| **Nouvelle action POST** | L.178, après `$action = $_POST['action']` | Centralisation des actions | Moyen | Suppression utilisateur, export CSV |
| **Nouvelle page dans permissions** | `$availablePages` (L.328, L.684) | Liste unique des pages ACL | Faible | Ajouter une nouvelle page applicative |
| **Nouvelle section (onglet)** | Après `#factures` | Même pattern que SAV/Paiements/Factures | Faible | Section Import, Logs, etc. |
| **Colonnes tableau utilisateurs** | `<thead>` L.2088, `updateTable()` L.2603 | Structure du tableau | Faible | Colonne "Dernière connexion" |
| **Validation utilisateur** | `validateUserData()` L.115 | Point unique de validation | Moyen | Règles métier supplémentaires |
| **Cache rôles** | `getAvailableRoles()` L.49 | Éviter requêtes répétées | Faible | Invalidation manuelle, TTL configurable |
| **Recherche AJAX** | `performSearch()` L.2649 | Endpoint dédié | Faible | Filtres avancés, pagination |
| **Quick actions** | L.1873 | Barre d'actions rapides | Faible | Lien vers Historique, Export |

---

## 7. Problèmes identifiés

### Logique dupliquée

- **$availablePages** : défini deux fois (L.328 dans save_permissions, L.684 pour l'affichage)
- **Recherche** : logique de recherche côté PHP (L.331-366) et côté API (profil_search_users L.68-75) – critères légèrement différents (PHP : rôle + statut partiels, API : nom/prénom/email commence par)

### Code mort / inutilisé

- **decode_msg($row)** : définie L.747, aucun appel trouvé dans profil.php

### Variables globales / fragilité

- `$ROLES` : dépend du cache `roles_enum.json` ; si fichier corrompu, fallback sur `$defaultRoles`
- Variables PHP injectées en JS : `currentUserId`, `hasRestrictions`, `isAdminOrDirigeant`, `csrfToken` – si mal échappées, risque XSS (actuellement via `json_encode`)

### Validations manquantes

- **Réinit mot de passe** : pas de confirmation (double saisie)
- **Email** : pas de vérification de format côté client (type="email" uniquement)

### Incohérences front/back

- **Recherche** : formulaire GET avec `action="/public/profil.php"` mais le JS fait `preventDefault` et utilise l’API – la soumission manuelle du formulaire ferait un GET classique (comportement différent de l’AJAX)
- **Statut paiements** : badges utilisent `valide`, `en_attente` – à vérifier avec les vrais statuts en base (enum)

### Listeners doublés

- Aucun doublon identifié.

### Bugs potentiels

- **savList** : la colonne `type_panne` peut ne pas exister (agenda.php vérifie avec columnExists) – profil.php ne fait pas cette vérification
- **Factures** : colspan="10" dans le message "Aucune facture" mais le tableau a 12 colonnes (Date, Numéro, Client, Type, Montant HT, TVA, Total TTC, Statut, PDF, Méthode paiement, Justificatif, Actions)

### Problèmes UX

- Pas de feedback visuel immédiat sur les boutons Activer/Désactiver (submit classique)
- Panneaux SAV/Paiements/Factures cachés par défaut (`display: none`) – l’utilisateur doit cliquer sur une quick-action pour les voir

### Problèmes maintenabilité

- **Fichier très long** (~2850 lignes) : mélange PHP, HTML, CSS inline, JS inline
- Styles inline : ~1000 lignes de `<style>` dans la page
- Pas de séparation claire entre logique métier et présentation

---

## 8. Plan d'amélioration préparatoire

### État actuel

La page profil est une page de gestion des utilisateurs avec :
- CRUD utilisateurs (création, modification, activation/désactivation)
- Réinitialisation mot de passe
- Gestion des permissions (ACL) par page
- Recherche utilisateurs en temps réel (AJAX)
- Consultation SAV, paiements, factures (sections repliables)
- Audit des actions via historique
- Protections : auth, CSRF, requêtes préparées, XSS

### Points faibles principaux

1. **Fichier monolithique** : PHP + HTML + CSS + JS dans un seul fichier
2. **Duplication** : `$availablePages` en double, logique de recherche PHP vs API
3. **Code mort** : `decode_msg` non utilisée
4. **Incohérence colspan** : factures (10 vs 12 colonnes)
5. **Colonne type_panne** : possible absence en base pour SAV
6. **Validation mot de passe** : pas de confirmation côté formulaire

### Améliorations recommandées

| Priorité | Amélioration | Effort |
|----------|--------------|--------|
| 1 | Extraire le CSS inline vers profil.css | Faible |
| 2 | Extraire le JS inline vers assets/js/profil.js | Moyen |
| 3 | Factoriser `$availablePages` en une seule définition | Faible |
| 4 | Supprimer ou utiliser `decode_msg` | Faible |
| 5 | Corriger colspan factures (12) | Faible |
| 6 | Vérifier existence colonne type_panne (comme agenda.php) | Faible |
| 7 | Ajouter confirmation mot de passe (champ + validation) | Moyen |
| 8 | Harmoniser recherche PHP/API (critères communs) | Moyen |
| 9 | Découper profil.php en includes (sections, formulaires) | Élevé |

### Ordre recommandé

1. Corrections rapides : colspan, type_panne, decode_msg
2. Factorisation : $availablePages
3. Extraction CSS
4. Extraction JS
5. Renforcement validation (mot de passe)
6. Refactorisation structurelle (includes)
