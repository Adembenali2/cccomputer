# Rapport A→Z : Problèmes SMTP et PDF sur Railway

## 1. LISTE DES ERREURS RENCONTRÉES

### Erreur #1 : 404 Not Found sur `/API/test_smtp.php`

**Symptômes :**
- Requête GET/POST vers `https://cccomputer-production.up.railway.app/API/test_smtp.php`
- Réponse HTTP 404 "The requested resource /API/test_smtp.php was not found on this server"

**Cause racine :**
- Railway utilise Apache (via Dockerfile) avec `WORKDIR /var/www/html`
- Apache sert depuis `/var/www/html` (racine du projet)
- Le fichier `API/test_smtp.php` existe mais n'est pas accessible car :
  - Soit le déploiement n'a pas inclus le fichier
  - Soit Apache ne route pas correctement les requêtes `/API/`
  - Soit Railway utilise Caddy en reverse proxy et la racine est `/app` au lieu de `/var/www/html`

**Preuve :**
- Dockerfile ligne 62 : `WORKDIR /var/www/html`
- Dockerfile ligne 83 : `COPY . /var/www/html`
- Caddyfile ligne 4 : `root * /app` (non utilisé si Dockerfile présent)

**Solution :**
1. Créer `public/API/test_smtp.php` (accessible via `/API/test_smtp.php` si `public/` est la racine web)
2. Créer `public/test_smtp.php` comme fallback (accessible directement)
3. S'assurer que le fichier est bien commité et déployé

---

### Erreur #2 : 500 "PDF introuvable" dans `factures_envoyer_email.php`

**Symptômes :**
- Envoi d'email de facture échoue avec erreur 500
- Message : "PDF introuvable" ou "Le fichier PDF est introuvable sur le serveur"
- Logs : `findPdfPath() - PDF introuvable après X tentatives`

**Cause racine :**
- **Stockage éphémère Railway** : Les fichiers uploadés dans `/var/www/html/uploads/` sont perdus lors des redéploiements
- Le chemin `pdf_path` enregistré en DB pointe vers un fichier qui n'existe plus
- `MailerService::findPdfPath()` ne trouve pas le fichier après plusieurs tentatives

**Preuve :**
- Railway utilise un filesystem éphémère (redéploiement = perte des fichiers)
- Les PDFs sont générés dans `uploads/factures/YYYY/` mais disparaissent après redéploiement
- Le code actuel tente de régénérer mais échoue si le PDF n'est pas trouvé

**Solution :**
1. Fallback robuste : Si PDF introuvable, régénérer dans `/tmp` (toujours disponible)
2. Utiliser `generateInvoicePdf()` avec `$outputDir = sys_get_temp_dir()`
3. Nettoyer le fichier temporaire après envoi

---

## 2. POURQUOI RAILWAY DÉCLENCHE CES PROBLÈMES

### Architecture Railway

```
┌─────────────────────────────────────┐
│  Railway Platform                   │
│  ┌───────────────────────────────┐ │
│  │  Docker Container              │ │
│  │  ┌───────────────────────────┐│ │
│  │  │ Apache (port 80)          ││ │
│  │  │ WORKDIR: /var/www/html    ││ │
│  │  │                           ││ │
│  │  │ Filesystem ÉPHÉMÈRE       ││ │
│  │  │ ❌ uploads/ → perdu        ││ │
│  │  │ ✅ /tmp → persiste         ││ │
│  │  └───────────────────────────┘│ │
│  └───────────────────────────────┘ │
└─────────────────────────────────────┘
```

**Points clés :**
1. **Document Root** : `/var/www/html` (Apache) ou `/app` (si Caddy)
2. **Stockage éphémère** : Tous les fichiers sauf `/tmp` sont perdus au redéploiement
3. **Routing** : Apache sert directement les fichiers PHP depuis la racine

---

## 3. SOLUTIONS IMPLÉMENTÉES

### Solution #1 : Endpoint SMTP test accessible

**Fichiers créés :**
- `public/API/test_smtp.php` : Endpoint principal (si `public/` est la racine)
- `public/test_smtp.php` : Fallback direct (accessible sans `/API/`)
- `API/test_smtp.php` : Version originale (si racine = projet)

**Stratégie :**
- Les 3 fichiers pointent vers le même code
- Si l'un ne fonctionne pas, les autres servent de fallback
- Tous utilisent les mêmes chemins relatifs (`__DIR__ . '/../'`)

### Solution #2 : Fallback PDF robuste

**Logique :**
1. Tenter `MailerService::findPdfPath()` (cherche dans uploads/)
2. Si échec → Régénérer dans `/tmp` via `generateInvoicePdf()`
3. Envoyer l'email avec le PDF temporaire
4. Nettoyer `/tmp` après envoi

**Avantages :**
- Fonctionne même si le PDF original est perdu
- `/tmp` est toujours disponible sur Railway
- Pas de pollution du filesystem (nettoyage automatique)

---

## 4. FICHIERS FINAUX (CODE COMPLET)

Voir les fichiers suivants dans le projet :
- `public/API/test_smtp.php`
- `public/test_smtp.php`
- `API/factures_envoyer_email.php` (mis à jour)
- `API/factures_generate_pdf_content.php` (vérifié)
- `src/Mail/MailerFactory.php` (vérifié)
- `src/Mail/MailerService.php` (vérifié)

---

## 5. CHECKLIST DÉPLOIEMENT RAILWAY

### Étape 1 : Git (Local)

```bash
# Vérifier les fichiers modifiés
git status

# Ajouter les fichiers
git add public/API/test_smtp.php
git add public/test_smtp.php
git add API/test_smtp.php
git add API/factures_envoyer_email.php
git add RAPPORT_SMTP_RAILWAY.md

# Commit
git commit -m "Fix: SMTP test endpoint + PDF fallback pour Railway"

# Push
git push origin main
```

### Étape 2 : Variables d'environnement Railway

**Service : `cccomputer` (PAS MySQL)**

Variables à configurer dans Railway Dashboard :

```
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@brevo.com
SMTP_PASSWORD=votre-password-brevo
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=Camson Group - Facturation
SMTP_REPLY_TO=facture@camsongroup.fr
SMTP_TEST_TOKEN=votre-token-secret-aleatoire
SMTP_DISABLE_VERIFY=false
```

**Important :**
- `SMTP_TEST_TOKEN` : Générer un token aléatoire (ex: `openssl rand -hex 32`)
- Ne PAS mettre de secrets dans le code
- Vérifier que toutes les variables sont définies

### Étape 3 : Redéploiement

1. Railway détecte automatiquement le push Git
2. Build Docker démarre automatiquement
3. Attendre la fin du build (vérifier les logs)
4. Vérifier que le service est "Active"

### Étape 4 : Tests

#### Test A : Vérifier que `public/` est servi

```bash
# Windows PowerShell
curl https://cccomputer-production.up.railway.app/ping.txt

# Linux/Mac
curl https://cccomputer-production.up.railway.app/ping.txt
```

**Résultat attendu :** `pong` (si le fichier `public/ping.txt` existe)

#### Test B : GET `/test_smtp.php`

```bash
# Windows PowerShell
curl https://cccomputer-production.up.railway.app/test_smtp.php

# Linux/Mac
curl https://cccomputer-production.up.railway.app/test_smtp.php
```

**Résultat attendu :**
```json
{
  "ok": true,
  "message": "Endpoint de test SMTP disponible",
  "method": "POST",
  "required_params": ["token", "to"],
  "note": "Utilisez POST avec un token valide pour envoyer un email de test"
}
```

#### Test C : GET `/API/test_smtp.php`

```bash
# Windows PowerShell
curl https://cccomputer-production.up.railway.app/API/test_smtp.php

# Linux/Mac
curl https://cccomputer-production.up.railway.app/API/test_smtp.php
```

**Résultat attendu :** Même JSON que Test B

#### Test D : POST `/test_smtp.php` avec token

```bash
# Windows PowerShell
$body = '{\"token\":\"votre-token\",\"to\":\"test@example.com\"}'
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php `
  -H "Content-Type: application/json" `
  -d $body

# Linux/Mac
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{"token":"votre-token","to":"test@example.com"}'
```

**Résultat attendu :**
```json
{
  "ok": true,
  "message": "Email envoyé",
  "to": "test@example.com",
  "timestamp": "2025-01-XX XX:XX:XX"
}
```

#### Test E : Test réel "Facture Mail"

1. Se connecter à l'application
2. Aller sur la page des factures
3. Sélectionner une facture
4. Cliquer sur "Envoyer par email"
5. Vérifier que l'email est reçu avec le PDF

**Vérifications :**
- Email reçu dans la boîte de réception
- PDF attaché et lisible
- Pas d'erreur 500 dans les logs Railway

---

## 6. COMMANDES DE TEST EXACTES

### Windows PowerShell

```powershell
# Test GET
curl https://cccomputer-production.up.railway.app/test_smtp.php

# Test POST (avec variables)
$token = "votre-token-secret"
$email = "test@example.com"
$body = "{`"token`":`"$token`",`"to`":`"$email`"}"
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php `
  -H "Content-Type: application/json" `
  -d $body

# Test POST (une ligne)
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php -H "Content-Type: application/json" -d '{\"token\":\"votre-token\",\"to\":\"test@example.com\"}'
```

### Linux/Mac

```bash
# Test GET
curl https://cccomputer-production.up.railway.app/test_smtp.php

# Test POST (avec variables)
TOKEN="votre-token-secret"
EMAIL="test@example.com"
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php \
  -H "Content-Type: application/json" \
  -d "{\"token\":\"$TOKEN\",\"to\":\"$EMAIL\"}"

# Test POST (une ligne)
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{"token":"votre-token","to":"test@example.com"}'
```

---

## 7. SÉCURITÉ

### ✅ Implémenté

1. **Token obligatoire** : `SMTP_TEST_TOKEN` requis pour POST
2. **hash_equals()** : Protection contre timing attacks
3. **Sanitization** : Mots de passe masqués dans les logs
4. **Path traversal** : Protection dans `findPdfPath()`
5. **Validation email** : `filter_var()` avec `FILTER_VALIDATE_EMAIL`
6. **Validation JSON** : Vérification stricte des données

### ⚠️ À vérifier

1. **Variables d'environnement** : Toutes définies dans Railway
2. **Token fort** : `SMTP_TEST_TOKEN` aléatoire et long (32+ caractères)
3. **HTTPS** : Railway force HTTPS (vérifié automatiquement)
4. **Logs** : Pas de secrets dans les réponses JSON

---

## 8. SYNTHÈSE FINALE

### Si ça ne marche toujours pas, vérifier :

#### Problème 1 : 404 sur `/API/test_smtp.php`

1. **Vérifier le déploiement**
   ```bash
   # Dans Railway Dashboard → Deployments
   # Vérifier que le dernier déploiement est "Active"
   ```

2. **Vérifier les fichiers dans le conteneur**
   ```bash
   # Railway Dashboard → Service → Shell
   ls -la /var/www/html/API/test_smtp.php
   ls -la /var/www/html/public/API/test_smtp.php
   ls -la /var/www/html/public/test_smtp.php
   ```

3. **Vérifier Apache**
   ```bash
   # Railway Dashboard → Service → Logs
   # Chercher les erreurs Apache
   ```

4. **Tester les autres endpoints API**
   ```bash
   curl https://cccomputer-production.up.railway.app/API/chatroom_get.php
   # Si ça fonctionne, le problème est spécifique à test_smtp.php
   ```

#### Problème 2 : 500 "PDF introuvable"

1. **Vérifier les logs Railway**
   ```
   Railway Dashboard → Service → Logs
   Chercher : [MAIL] ou findPdfPath
   ```

2. **Vérifier les variables d'environnement**
   ```
   Railway Dashboard → Service → Variables
   Vérifier : SMTP_* sont toutes définies
   ```

3. **Tester la génération PDF manuelle**
   ```bash
   # Dans Railway Shell
   php -r "require 'vendor/autoload.php'; echo sys_get_temp_dir();"
   # Vérifier que /tmp est accessible
   ```

4. **Vérifier les permissions**
   ```bash
   # Railway Shell
   ls -la /tmp
   touch /tmp/test.txt
   # Si erreur, problème de permissions
   ```

#### Problème 3 : Email non envoyé

1. **Vérifier SMTP_TEST_TOKEN**
   ```bash
   # Railway Dashboard → Variables
   # Vérifier que SMTP_TEST_TOKEN est défini et correspond au curl
   ```

2. **Vérifier les credentials SMTP**
   ```
   Railway Dashboard → Variables
   SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD
   ```

3. **Tester avec un autre client SMTP**
   ```bash
   # Utiliser telnet ou openssl pour tester la connexion SMTP
   openssl s_client -connect smtp-relay.brevo.com:587 -starttls smtp
   ```

4. **Vérifier les logs PHPMailer**
   ```
   Railway Dashboard → Logs
   Chercher : [SMTP_TEST] ou PHPMailer
   ```

---

## 9. FICHIERS À CRÉER/MODIFIER

### Fichiers à créer :
- ✅ `public/API/test_smtp.php`
- ✅ `public/test_smtp.php`
- ✅ `public/ping.txt` (pour test)

### Fichiers à modifier :
- ✅ `API/factures_envoyer_email.php` (fallback PDF)

### Fichiers à vérifier (déjà OK) :
- ✅ `API/factures_generate_pdf_content.php`
- ✅ `src/Mail/MailerFactory.php`
- ✅ `src/Mail/MailerService.php`

---

## 10. NOTES IMPORTANTES

1. **Railway stockage éphémère** : Ne jamais compter sur `uploads/` pour persister
2. **Utiliser `/tmp`** : Toujours disponible, nettoyé automatiquement
3. **Multiple endpoints** : Créer plusieurs chemins pour le même endpoint (fallback)
4. **Logs détaillés** : Tous les logs utilisent des préfixes `[SMTP_TEST]`, `[MAIL]`
5. **Sécurité** : Token obligatoire, validation stricte, pas de secrets exposés

---

**Date de création :** 2025-01-XX  
**Version :** 1.0  
**Auteur :** Auto (Cursor AI)

