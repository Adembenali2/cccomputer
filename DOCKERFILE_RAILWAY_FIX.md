# 🔧 Correction du Dockerfile pour Railway

## ❌ Problème identifié

Railway ne peut pas récupérer l'image Docker `mirror.gcr.io/library/php:8.3-apache` à cause d'un **timeout réseau** :

```
failed to resolve source metadata for mirror.gcr.io/library/php:8.3-apache: 
failed to do request: Head "https://mirror.gcr.io/v2/library/php/manifests/8.3-apache": 
dial tcp 142.250.102.82:443: i/o timeout
```

### Pourquoi ça bloque ?

1. **`mirror.gcr.io`** est un miroir Google Cloud Registry qui n'est pas toujours accessible
2. Le miroir peut être lent ou indisponible depuis certains réseaux (comme Railway)
3. Railway a besoin d'une image Docker **stable et accessible** depuis Docker Hub

---

## ✅ Solution appliquée

### 1. Remplacement de l'image de base

**AVANT** :
```dockerfile
FROM mirror.gcr.io/library/php:8.3-apache
```

**APRÈS** :
```dockerfile
FROM php:8.3-apache
```

✅ Utilise directement l'image **officielle** de Docker Hub, qui est :
- Stable et maintenue
- Accessible depuis Railway
- Pas de problème de timeout

---

### 2. Extensions PHP ajoutées

J'ai ajouté toutes les extensions nécessaires pour votre projet :

| Extension | Usage |
|-----------|-------|
| `pdo_mysql` | Connexion PDO à MySQL (utilisé partout) |
| `mysqli` | Connexion MySQLi (si nécessaire) |
| `gd` | Manipulation d'images (QR codes, etc.) |
| `zip` | **NOUVEAU** - Pour PhpSpreadsheet (export Excel) |
| `intl` | **NOUVEAU** - Formats de nombres/dates (formatNumber) |
| `mbstring` | **NOUVEAU** - Fonctions de chaînes multioctets |

---

### 3. Optimisations pour Railway

#### Variables d'environnement
```dockerfile
ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1
```
- Évite les prompts interactifs pendant le build
- Permet à Composer de s'exécuter sans erreur

#### Installation optimisée des dépendances
- Toutes les dépendances système installées en une seule commande
- Nettoyage immédiat pour réduire la taille de l'image

#### Configuration Apache
```dockerfile
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
```
- Évite les warnings Apache sur Railway

#### Gestion des permissions
```dockerfile
RUN chown -R www-data:www-data /var/www/html
```
- Assure que Apache peut lire/écrire les fichiers

---

## 📋 Dockerfile corrigé

Le nouveau Dockerfile :

✅ Utilise `php:8.3-apache` depuis Docker Hub  
✅ Installe toutes les extensions nécessaires  
✅ Optimisé pour Railway  
✅ Gère correctement Composer  
✅ Configure Apache correctement  

---

## 🚀 Déploiement sur Railway

### Étapes

1. **Commit le nouveau Dockerfile** :
   ```bash
   git add Dockerfile
   git commit -m "Fix: Use official PHP image from Docker Hub for Railway"
   git push
   ```

2. **Railway va automatiquement** :
   - Détecter le nouveau Dockerfile
   - Lancer un nouveau build
   - Utiliser l'image officielle `php:8.3-apache`

3. **Vérifier le build** :
   - Le build devrait maintenant réussir
   - Plus de timeout sur `mirror.gcr.io`

---

## 🔍 Vérifications

### Extensions PHP installées

Après le déploiement, vous pouvez vérifier les extensions avec :

```php
<?php
phpinfo();
```

Ou dans un script :
```php
<?php
$extensions = ['pdo_mysql', 'mysqli', 'gd', 'zip', 'intl', 'mbstring'];
foreach ($extensions as $ext) {
    echo $ext . ': ' . (extension_loaded($ext) ? '✅' : '❌') . "\n";
}
```

---

## 📦 Dépendances système installées

- `git` - Pour Composer
- `unzip` - Pour décompresser les packages
- `curl` - Pour télécharger Composer
- `libpng-dev` - Pour GD (images PNG)
- `libjpeg-dev` - Pour GD (images JPEG)
- `libfreetype6-dev` - Pour GD (polices)
- `libzip-dev` - Pour l'extension ZIP
- `libicu-dev` - Pour l'extension INTL

---

## ⚠️ Notes importantes

### Composer

Le Dockerfile utilise maintenant une logique plus robuste :
- Si `composer.lock` existe → `composer install` (plus rapide)
- Sinon → `composer update` (pour générer le lock)

### Port

Railway mappe automatiquement le port, donc `EXPOSE 80` est suffisant.

### Variables d'environnement Railway

Le Dockerfile n'a pas besoin de connaître les variables d'environnement Railway (MYSQLHOST, etc.) car elles sont injectées au runtime par Railway.

---

## ✅ Résultat attendu

Après le déploiement :

✅ Build réussi sur Railway  
✅ Image Docker construite correctement  
✅ Toutes les extensions PHP disponibles  
✅ Apache configuré et fonctionnel  
✅ Application accessible  

---

## 🐛 Si le problème persiste

Si Railway a encore des problèmes :

1. **Vérifier les logs Railway** pour d'autres erreurs
2. **Tester localement** :
   ```bash
   docker build -t cccomputer .
   docker run -p 8080:80 cccomputer
   ```
3. **Vérifier la connexion à la base de données** (variables d'environnement Railway)

---

## 📝 Résumé des changements

| Élément | Avant | Après |
|---------|-------|-------|
| Image de base | `mirror.gcr.io/library/php:8.3-apache` | `php:8.3-apache` |
| Extensions | pdo_mysql, mysqli, gd | + zip, intl, mbstring |
| Optimisations | Basiques | Optimisées pour Railway |
| Gestion Composer | Simple | Robuste avec fallback |

---

Le Dockerfile est maintenant **prêt pour Railway** ! 🚀

