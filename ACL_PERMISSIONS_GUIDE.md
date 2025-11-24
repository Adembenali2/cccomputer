# Guide d'utilisation du système ACL (Gestion des Permissions)

## Vue d'ensemble

Le système ACL (Access Control List) permet de gérer finement les permissions d'accès aux pages pour chaque utilisateur, indépendamment de leur rôle. Si aucune permission explicite n'est définie pour un utilisateur, le système utilise les rôles par défaut (fallback).

## Structure de la base de données

### Table `user_permissions`

```sql
CREATE TABLE `user_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `page` varchar(100) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = autorisé, 0 = interdit',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_page` (`user_id`, `page`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_page` (`page`),
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Installation

1. **Exécuter la migration SQL** :
   ```bash
   php sql/run_migration_user_permissions.php
   ```
   
   Ou manuellement :
   ```sql
   source sql/migration_create_user_permissions.sql
   ```

## Pages disponibles

Les pages suivantes peuvent être contrôlées via le système ACL :

- `dashboard` - Dashboard
- `agenda` - Agenda
- `clients` - Clients
- `client_fiche` - Fiche Client
- `historique` - Historique
- `profil` - Gestion Utilisateurs
- `maps` - Cartes & Planification
- `messagerie` - Messagerie
- `sav` - SAV
- `livraison` - Livraisons
- `stock` - Stock
- `photocopieurs_details` - Détails Photocopieurs

## Utilisation dans le code

### Méthode 1 : Utiliser `authorize_page()` (recommandé)

Cette fonction vérifie les permissions et redirige automatiquement si l'accès est refusé.

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_role.php';

// Vérifier l'accès à la page 'historique'
// Si aucune permission explicite n'existe, utiliser les rôles par défaut
authorize_page('historique', ['Admin', 'Dirigeant']);

// Le reste du code de la page...
?>
```

### Méthode 2 : Utiliser `checkPagePermission()` pour une vérification conditionnelle

Cette fonction retourne un booléen sans rediriger.

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_role.php';

// Vérifier si l'utilisateur a accès
if (!checkPagePermission('maps', ['Admin', 'Dirigeant'])) {
    // Afficher un message d'erreur ou rediriger manuellement
    header('Location: /redirection/acces_interdit.php');
    exit;
}

// Le reste du code...
?>
```

### Méthode 3 : Migration progressive depuis `authorize_roles()`

Vous pouvez remplacer progressivement les appels à `authorize_roles()` :

**Avant** :
```php
authorize_roles(['Admin', 'Dirigeant']);
```

**Après** :
```php
authorize_page('nom_de_la_page', ['Admin', 'Dirigeant']);
```

## Interface de gestion

### Accès à l'interface

1. Connectez-vous en tant qu'**Admin** ou **Dirigeant**
2. Accédez à la page **Gestion Utilisateurs** (`/public/profil.php`)
3. Faites défiler jusqu'à la section **"Gestion des Permissions"** en bas de la page

### Utilisation

1. **Sélectionner un utilisateur** : Choisissez l'utilisateur dans le menu déroulant
2. **Gérer les permissions** : Utilisez les toggles pour autoriser/interdire l'accès à chaque page
3. **Actions rapides** :
   - **"Tout autoriser"** : Active toutes les permissions
   - **"Tout interdire"** : Désactive toutes les permissions
4. **Enregistrer** : Cliquez sur "Enregistrer les permissions"

### Comportement

- Si une permission est **cochée** (toggle activé) : L'utilisateur a accès à la page
- Si une permission est **décochée** (toggle désactivé) : L'utilisateur n'a pas accès à la page
- Si aucune permission n'est définie : Le système utilise les rôles par défaut (comportement normal)

## Logique de fonctionnement

### Ordre de vérification

1. **Permission explicite** : Si une entrée existe dans `user_permissions` pour l'utilisateur et la page, cette valeur est utilisée
2. **Fallback sur les rôles** : Si aucune permission explicite n'existe, le système vérifie si le rôle de l'utilisateur est dans la liste des rôles autorisés
3. **Autorisation par défaut** : Si aucun rôle par défaut n'est spécifié et qu'aucune permission n'existe, l'accès est autorisé (pour éviter de bloquer l'accès si le système ACL n'est pas encore configuré)

### Exemple

```php
// Page 'historique.php'
authorize_page('historique', ['Admin', 'Dirigeant']);

// Scénario 1 : Utilisateur "Jean" (Admin)
// - Permission explicite : Aucune
// - Rôle : Admin (dans la liste autorisée)
// → Accès AUTORISÉ (fallback sur rôle)

// Scénario 2 : Utilisateur "Marie" (Technicien)
// - Permission explicite : allowed = 1 pour 'historique'
// - Rôle : Technicien (pas dans la liste autorisée)
// → Accès AUTORISÉ (permission explicite prioritaire)

// Scénario 3 : Utilisateur "Paul" (Technicien)
// - Permission explicite : allowed = 0 pour 'historique'
// - Rôle : Technicien (pas dans la liste autorisée)
// → Accès REFUSÉ (permission explicite prioritaire)
```

## Migration des pages existantes

Pour migrer une page existante vers le système ACL :

1. **Identifier le nom de la page** : Utilisez le nom du fichier sans extension (ex: `historique` pour `historique.php`)

2. **Remplacer `authorize_roles()`** :
   ```php
   // Avant
   require_once __DIR__ . '/../includes/auth_role.php';
   authorize_roles(['Admin', 'Dirigeant']);
   
   // Après
   require_once __DIR__ . '/../includes/auth_role.php';
   authorize_page('historique', ['Admin', 'Dirigeant']);
   ```

3. **Tester** : Vérifiez que les utilisateurs avec les rôles par défaut ont toujours accès

4. **Configurer les permissions** : Utilisez l'interface de gestion pour définir des permissions personnalisées si nécessaire

## Notes importantes

- **Cascade DELETE** : Si un utilisateur est supprimé, toutes ses permissions sont automatiquement supprimées (CASCADE)
- **Performance** : Les permissions sont mises en cache dans la session pour éviter des requêtes répétées
- **Sécurité** : Seuls les **Admin** et **Dirigeant** peuvent gérer les permissions
- **Compatibilité** : Le système est rétrocompatible avec le système de rôles existant

## Dépannage

### La table n'existe pas

Si vous obtenez une erreur indiquant que la table `user_permissions` n'existe pas :

```bash
php sql/run_migration_user_permissions.php
```

### Les permissions ne s'appliquent pas

1. Vérifiez que la page utilise `authorize_page()` ou `checkPagePermission()`
2. Vérifiez que le nom de la page correspond exactement à celui défini dans `$availablePages`
3. Vérifiez les logs d'erreur PHP pour des erreurs de requête SQL

### Un utilisateur ne peut plus accéder à une page

1. Vérifiez les permissions dans l'interface de gestion
2. Vérifiez que le rôle par défaut de l'utilisateur est dans la liste autorisée
3. Si nécessaire, supprimez les permissions explicites pour revenir au comportement par défaut

