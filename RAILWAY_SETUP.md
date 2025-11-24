# Configuration Railway pour TCPDF

## Installation des dépendances

Railway détecte automatiquement votre `composer.json` et installe les dépendances via le Dockerfile.

### Étapes pour déployer sur Railway

1. **Mettre à jour composer.lock localement** (si vous travaillez en local) :
   ```bash
   composer update
   ```
   Cela mettra à jour le fichier `composer.lock` avec TCPDF.

2. **Pousser les changements sur Railway** :
   - Commitez et poussez vos changements (composer.json, composer.lock, Dockerfile)
   - Railway détectera automatiquement les changements et reconstruira l'image

3. **Vérification du build** :
   - Le Dockerfile exécute automatiquement `composer install` lors du build
   - TCPDF sera installé dans le dossier `vendor/`

## Extensions PHP requises

Le Dockerfile a été mis à jour pour inclure :
- ✅ `pdo_mysql` et `mysqli` (déjà présentes)
- ✅ `gd` (pour la génération d'images dans les PDFs)
- ✅ `zlib` (généralement déjà inclus dans PHP)

## Vérification après déploiement

Après le déploiement sur Railway, vous pouvez vérifier que TCPDF est installé en testant :
- L'endpoint `/API/generate_invoice_pdf.php?client_id=1&invoice_number=FAC-20241220-00001`
- Si une erreur survient, vérifiez les logs Railway

## Notes importantes

- Le dossier `vendor/` est généré automatiquement lors du build Docker
- Ne commitez pas le dossier `vendor/` (il devrait être dans `.gitignore`)
- Railway reconstruira l'image à chaque push si le Dockerfile ou composer.json change

## Dépannage

Si TCPDF n'est pas trouvé après le déploiement :
1. Vérifiez les logs de build Railway
2. Assurez-vous que `composer install` s'exécute correctement
3. Vérifiez que le chemin `vendor/autoload.php` est accessible
4. Vérifiez que les extensions PHP (gd) sont bien installées

