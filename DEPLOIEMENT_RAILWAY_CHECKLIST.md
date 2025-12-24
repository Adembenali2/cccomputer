# Checklist Déploiement Railway - SMTP & PDF

**Version :** 1.1  
**Date :** 2025-01-XX

## ✅ ÉTAPE 1 : Vérification locale

```bash
# Vérifier que tous les fichiers sont présents
ls -la public/test_smtp.php
ls -la public/API/test_smtp.php
ls -la public/ping.txt
ls -la API/factures_envoyer_email.php
ls -la API/factures_generate_pdf_content.php
```

## ✅ ÉTAPE 2 : Git Commit & Push

```bash
# Vérifier les modifications
git status

# Ajouter les fichiers
git add public/test_smtp.php
git add public/API/test_smtp.php
git add public/ping.txt
git add API/factures_envoyer_email.php
git add API/factures_generate_pdf_content.php
git add RAPPORT_SMTP_RAILWAY.md
git add DEPLOIEMENT_RAILWAY_CHECKLIST.md

# Commit
git commit -m "Fix: SMTP test endpoint + PDF fallback pour Railway

- Endpoint principal: public/test_smtp.php
- Fallback PDF robuste dans /tmp pour Railway
- Correction injection SQL dans generateInvoicePdf
- Documentation complète v1.1"

# Push
git push origin main
```

## ✅ ÉTAPE 3 : Variables d'environnement Railway

**Railway Dashboard → Service `cccomputer` → Variables**

### Variables SMTP (Brevo) - Requises

```
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@brevo.com
SMTP_PASSWORD=votre-password-brevo
SMTP_FROM_EMAIL=facturemail@cccomputer.fr
SMTP_FROM_NAME=Camson Group - Facturation
SMTP_REPLY_TO=facture@camsongroup.fr
```

### Variable Token Test - Requise

```
SMTP_TEST_TOKEN=<générer-un-token-aléatoire>
```

**Générer un token :**
```bash
# Linux/Mac
openssl rand -hex 32

# Windows PowerShell
-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | % {[char]$_})
```

### Variable optionnelle

```
SMTP_DISABLE_VERIFY=false
```

**⚠️ IMPORTANT :** 
- Ne jamais mettre `SMTP_DISABLE_VERIFY=true` en production sauf si absolument nécessaire.
- `SMTP_FROM_EMAIL=facturemail@cccomputer.fr` par défaut (domaine validé SPF/DKIM)
- Pour utiliser `facture@camsongroup.fr`, valider d'abord le domaine dans Brevo Dashboard

## ✅ ÉTAPE 4 : Redéploiement

1. Railway détecte automatiquement le push Git
2. Vérifier les logs de build dans Railway Dashboard
3. Attendre que le statut soit "Active"
4. Vérifier qu'il n'y a pas d'erreurs dans les logs

## ✅ ÉTAPE 5 : Validation du déploiement

### Test A : Vérifier que le commit est déployé

**Railway Dashboard → Service → Shell**

```bash
# Vérifier les fichiers
ls -la /var/www/html/public/test_smtp.php
ls -la /var/www/html/public/ping.txt

# Vérifier le commit
cd /var/www/html
git log -1 --oneline
```

**Résultat attendu :** Les fichiers existent et le commit correspond

---

### Test B : Déterminer le document root

**Railway Dashboard → Service → Shell**

```bash
# Vérifier DOCUMENT_ROOT
php -r "echo \$_SERVER['DOCUMENT_ROOT'] ?? 'non défini';"
```

**Interprétation :**
- Si `/var/www/html/public` → `public/` est la racine web
- Si `/var/www/html` → Racine du projet est la racine web

---

### Test C : Ping (vérifier que public/ est servi)

**Windows PowerShell :**
```powershell
curl https://cccomputer-production.up.railway.app/ping.txt
```

**Linux/Mac :**
```bash
curl https://cccomputer-production.up.railway.app/ping.txt
```

**Résultat attendu :** `pong`

**Interprétation :**
- ✅ Si `pong` → `public/` est la racine web servie
- ❌ Si 404 → `public/` n'est PAS la racine, ou le fichier n'est pas déployé

---

## ✅ ÉTAPE 6 : Tests fonctionnels

### Test D : GET /test_smtp.php (endpoint principal)

**Windows PowerShell :**
```powershell
curl https://cccomputer-production.up.railway.app/test_smtp.php
```

**Linux/Mac :**
```bash
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

---

### Test E : GET /API/test_smtp.php (optionnel)

**Windows PowerShell :**
```powershell
curl https://cccomputer-production.up.railway.app/API/test_smtp.php
```

**Linux/Mac :**
```bash
curl https://cccomputer-production.up.railway.app/API/test_smtp.php
```

**Résultat attendu :** Même JSON que Test D (si `/API/` est routé)

**Note :** Si 404, ce n'est pas grave. Utiliser `/test_smtp.php` en priorité.

---

### Test F : POST /test_smtp.php (avec token)

**Windows PowerShell :**
```powershell
$token = "votre-token-secret"
$email = "test@example.com"
$body = "{`"token`":`"$token`",`"to`":`"$email`"}"
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php `
  -H "Content-Type: application/json" `
  -d $body
```

**Linux/Mac :**
```bash
TOKEN="votre-token-secret"
EMAIL="test@example.com"
curl -X POST https://cccomputer-production.up.railway.app/test_smtp.php \
  -H "Content-Type: application/json" \
  -d "{\"token\":\"$TOKEN\",\"to\":\"$EMAIL\"}"
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

**Vérifier :** L'email doit arriver dans la boîte de réception de `test@example.com`

---

### Test G : Test réel "Facture Mail"

1. Se connecter à l'application
2. Aller sur `/public/view_facture.php?id=XXX` (remplacer XXX par un ID de facture)
3. Cliquer sur "Envoyer par email"
4. Entrer l'adresse email de destination
5. Cliquer sur "Envoyer"

**Vérifications :**
- ✅ Pas d'erreur 500
- ✅ Message de succès affiché
- ✅ Email reçu avec PDF attaché
- ✅ PDF lisible et complet

**Logs à vérifier dans Railway :**
```
[MAIL] PDF trouvé avec succès: ...
OU
[MAIL] fallback regen start - Facture ID: XXX
[MAIL] Génération PDF dans répertoire temporaire: /tmp
[MAIL] regen ok path=...
```

---

## ❌ DÉPANNAGE

### Problème : 404 sur `/test_smtp.php`

**Solutions :**
1. Vérifier que le fichier existe dans Railway :
   - Railway Dashboard → Service → Shell
   - `ls -la /var/www/html/public/test_smtp.php`

2. Vérifier que le commit est déployé :
   - Railway Dashboard → Service → Shell
   - `cd /var/www/html && git log -1 --oneline`

3. Vérifier le document root :
   - Railway Dashboard → Service → Shell
   - `php -r "echo \$_SERVER['DOCUMENT_ROOT'];"`
   - Si `/var/www/html/public` → Le fichier doit être accessible
   - Si `/var/www/html` → Tester `/public/test_smtp.php`

4. Vérifier que le déploiement est terminé :
   - Railway Dashboard → Deployments
   - Statut doit être "Active"

---

### Problème : 404 sur `/API/test_smtp.php`

**Solutions :**
1. **Utiliser `/test_smtp.php` à la place** (endpoint principal recommandé)

2. Si vous devez absolument utiliser `/API/` :
   - Vérifier que le fichier existe : `ls -la /var/www/html/public/API/test_smtp.php`
   - Vérifier que `/API/` est routé : `curl /API/chatroom_get.php` (doit fonctionner)

---

### Problème : 403 "Token invalide"

**Solutions :**
1. Vérifier que `SMTP_TEST_TOKEN` est défini dans Railway
2. Vérifier que le token dans le curl correspond exactement
3. Vérifier qu'il n'y a pas d'espaces avant/après le token
4. Régénérer le token si nécessaire

---

### Problème : 500 "Configuration SMTP invalide"

**Solutions :**
1. Vérifier toutes les variables SMTP dans Railway :
   - `SMTP_ENABLED=true`
   - `SMTP_HOST` (ex: `smtp-relay.brevo.com`)
   - `SMTP_PORT` (ex: `587`)
   - `SMTP_SECURE` (ex: `tls`)
   - `SMTP_USERNAME` (email Brevo)
   - `SMTP_PASSWORD` (password Brevo)

2. Vérifier les logs Railway :
   - Chercher `[SMTP_TEST]` ou `MailerException`

---

### Problème : 500 "PDF introuvable"

**Solutions :**
1. Vérifier les logs Railway :
   - Chercher `[MAIL]` ou `findPdfPath`
   - Vérifier si le fallback se déclenche (`[MAIL] regen ok`)

2. Vérifier que `/tmp` est accessible :
   - Railway Dashboard → Service → Shell
   - `ls -la /tmp`
   - `touch /tmp/test.txt` (doit fonctionner)

3. Vérifier les permissions :
   - Railway Dashboard → Service → Shell
   - `whoami` (doit être `www-data` ou similaire)

---

### Problème : Email non reçu

**Solutions :**
1. Vérifier les logs Railway :
   - Chercher `[SMTP_TEST]` ou `PHPMailer`
   - Vérifier les erreurs SMTP

2. Tester la connexion SMTP manuellement :
   ```bash
   # Railway Shell
   openssl s_client -connect smtp-relay.brevo.com:587 -starttls smtp
   ```

3. Vérifier les credentials Brevo :
   - Se connecter à Brevo Dashboard
   - Vérifier que le compte SMTP est actif
   - Vérifier que le password est correct

4. Vérifier le domaine FROM :
   - Si `facturemail@cccomputer.fr` → Doit être validé dans Brevo
   - Si `facture@camsongroup.fr` → Doit être validé SPF/DKIM

---

## 📋 RÉCAPITULATIF FINAL

### Fichiers créés/modifiés

- ✅ `public/test_smtp.php` (endpoint principal recommandé)
- ✅ `public/API/test_smtp.php` (optionnel)
- ✅ `public/ping.txt` (pour validation)
- ✅ `API/factures_envoyer_email.php` (fallback PDF ajouté)
- ✅ `API/factures_generate_pdf_content.php` (injection SQL corrigée)

### Variables Railway requises

- ✅ `SMTP_ENABLED=true`
- ✅ `SMTP_HOST=smtp-relay.brevo.com`
- ✅ `SMTP_PORT=587`
- ✅ `SMTP_SECURE=tls`
- ✅ `SMTP_USERNAME=...`
- ✅ `SMTP_PASSWORD=...`
- ✅ `SMTP_FROM_EMAIL=facturemail@cccomputer.fr` (par défaut)
- ✅ `SMTP_FROM_NAME=Camson Group - Facturation`
- ✅ `SMTP_REPLY_TO=facture@camsongroup.fr`
- ✅ `SMTP_TEST_TOKEN=...` (générer aléatoirement)

### URLs de test

- `https://cccomputer-production.up.railway.app/ping.txt` (validation)
- `https://cccomputer-production.up.railway.app/test_smtp.php` (endpoint principal)
- `https://cccomputer-production.up.railway.app/API/test_smtp.php` (optionnel)

---

## 📊 TABLEAU RÉCAPITULATIF : SYMPTÔME → CAUSE → FIX → TEST

| Symptôme | Cause probable | Fix | Test de validation |
|----------|---------------|-----|-------------------|
| **404 sur `/test_smtp.php`** | Fichier non déployé | Vérifier commit déployé, redéployer | `curl /ping.txt` doit retourner `pong` |
| **404 sur `/test_smtp.php`** | Document root différent | Vérifier `DOCUMENT_ROOT`, ajuster chemin | `curl /test_smtp.php` doit retourner JSON |
| **404 sur `/API/test_smtp.php`** | Routing bloque `/API/` | Utiliser `/test_smtp.php` (endpoint principal) | `curl /test_smtp.php` doit retourner JSON |
| **403 "Token invalide"** | `SMTP_TEST_TOKEN` manquant/incorrect | Vérifier variable Railway, régénérer token | `curl POST` avec token correct doit retourner `ok: true` |
| **500 "Configuration SMTP invalide"** | Variables SMTP manquantes/incorrectes | Vérifier toutes les variables `SMTP_*` dans Railway | `curl POST` doit retourner `ok: true` |
| **500 "PDF introuvable"** | PDF perdu (stockage éphémère) | Fallback automatique dans `/tmp` | Envoyer facture par email, vérifier logs `[MAIL] regen ok` |
| **Email non reçu** | Credentials SMTP incorrects | Vérifier `SMTP_USERNAME` et `SMTP_PASSWORD` dans Brevo | `curl POST` doit retourner `ok: true`, email reçu |
| **Email rejeté (spam)** | Domaine FROM non validé | Utiliser `facturemail@cccomputer.fr` (validé) | Email reçu dans boîte principale (pas spam) |

---

**Version :** 1.1  
**Date :** 2025-01-XX
