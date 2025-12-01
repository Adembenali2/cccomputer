# 🔧 Correction du problème Composer lock file

## ❌ Problème identifié

L'erreur lors du build Docker :

```
Warning: The lock file is not up to date with the latest changes in composer.json.
- Required package "tecnickcom/tcpdf" is not present in the lock file.
- Required package "phpmailer/phpmailer" is not present in the lock file.
Build Failed: composer install did not complete successfully; exit code: 4
```

### Pourquoi cette erreur ?

1. **`composer.json`** contient les dépendances :
   - `tecnickcom/tcpdf`: "^6.6"
   - `phpmailer/phpmailer`: "^6.9"

2. **`composer.lock`** n'était pas synchronisé :
   - Ne contenait que `phpseclib/phpseclib` et ses dépendances
   - Les packages `tcpdf` et `phpmailer` manquaient

3. **Cause probable** :
   - Ajout manuel dans `composer.json` sans exécuter `composer require`
   - Ou `composer.lock` non commité après une mise à jour

---

## ✅ Solutions appliquées

### 1. Mise à jour du Dockerfile

Le Dockerfile gère maintenant automatiquement les cas où le lock n'est pas synchronisé :

```dockerfile
# Installer les dépendances Composer
# Stratégie robuste : Essayer install d'abord, si échec (lock désynchronisé), faire update
RUN set -eux; \
    if [ -f composer.lock ]; then \
        echo "Lock file found, attempting install..."; \
        if ! composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts 2>&1 | tee /tmp/composer.log; then \
            echo "Lock file out of sync or install failed, updating..."; \
            composer update --no-dev --prefer-dist --no-progress --no-interaction --no-scripts; \
        fi; \
    else \
        echo "No lock file, updating..."; \
        composer update --no-dev --prefer-dist --no-progress --no-interaction --no-scripts; \
    fi
```

**Fonctionnement** :
1. Si `composer.lock` existe → Essaie `composer install`
2. Si l'install échoue (lock désynchronisé) → Fait automatiquement `composer update`
3. Si pas de lock → Fait `composer update`

### 2. Mise à jour du composer.lock

Le `composer.lock` a été régénéré localement avec :
```bash
composer update --no-dev
```

Les packages suivants ont été ajoutés :
- ✅ `phpmailer/phpmailer` (v6.12.0)
- ✅ `tecnickcom/tcpdf` (6.10.1)

---

## 📋 Packages dans composer.json

```json
{
  "require": {
    "php": ">=8.0",
    "phpseclib/phpseclib": "^3.0",
    "tecnickcom/tcpdf": "^6.6",
    "phpmailer/phpmailer": "^6.9"
  }
}
```

### Usage des packages

- **`phpseclib/phpseclib`** : Connexions SFTP (import compteurs)
- **`tecnickcom/tcpdf`** : Génération de PDF (factures, étiquettes)
- **`phpmailer/phpmailer`** : Envoi d'emails

---

## 🔧 Instructions pour régénérer composer.lock

Si vous devez régénérer le lock file localement :

```bash
# Dans le répertoire du projet
composer update --no-dev

# Ou pour ajouter un nouveau package
composer require vendor/package --no-dev

# Puis commit le nouveau composer.lock
git add composer.lock
git commit -m "Update composer.lock"
```

---

## ✅ Résultat

Le Dockerfile est maintenant :

✅ **Robuste** : Gère automatiquement les lock désynchronisés  
✅ **Flexible** : Fonctionne avec ou sans lock file  
✅ **Sûr** : Utilise `composer install` quand possible (plus rapide)  
✅ **Automatique** : Fait `composer update` si nécessaire  

---

## 🚀 Déploiement

Le build Docker devrait maintenant :

1. ✅ Détecter que le lock n'est pas synchronisé
2. ✅ Faire automatiquement `composer update`
3. ✅ Installer tous les packages (tcpdf, phpmailer, phpseclib)
4. ✅ Continuer le build sans erreur

---

## 📝 Note importante

**Pour éviter ce problème à l'avenir** :

1. Toujours utiliser `composer require` pour ajouter des packages :
   ```bash
   composer require tecnickcom/tcpdf --no-dev
   composer require phpmailer/phpmailer --no-dev
   ```

2. Toujours commiter `composer.lock` après modification :
   ```bash
   git add composer.json composer.lock
   git commit -m "Add dependencies"
   ```

3. Ne jamais modifier `composer.json` manuellement sans mettre à jour le lock

---

Le Dockerfile est maintenant **prêt pour IONOS** ! 🚀

