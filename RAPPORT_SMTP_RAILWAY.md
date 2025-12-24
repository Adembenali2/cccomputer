# Rapport A→Z : Problèmes SMTP et PDF sur Railway

**Version :** 1.1  
**Date :** 2025-01-XX

## 1. LISTE DES ERREURS RENCONTRÉES

### Erreur #1 : 404 Not Found sur `/API/test_smtp.php`

**Symptômes :**
- Requête GET/POST vers `https://cccomputer-production.up.railway.app/API/test_smtp.php`
- Réponse HTTP 404 "The requested resource /API/test_smtp.php was not found on this server"

**Cause racine :**
Le fichier `API/test_smtp.php` existe dans le code source mais n'est pas accessible via l'URL `/API/test_smtp.php`. Les causes possibles sont :

1. **Fichier non déployé** : Le commit contenant le fichier n'a pas été déployé sur Railway
2. **Document root différent** : La racine web servie par Railway n'est pas la racine du projet
3. **Routing bloque `/API/`** : Le serveur web (Apache/Caddy/Nginx) ne route pas correctement les requêtes vers `/API/`

**Solution :**
Utiliser l'endpoint principal recommandé : `/test_smtp.php` (fichier `public/test_smtp.php`)

Voir section 4 "Pourquoi 404 sur /API/test_smtp.php" pour plus de détails.

---

### Erreur #2 : 500 "PDF introuvable" dans `factures_envoyer_email.php`

**Symptômes :**
- Envoi d'email de facture échoue avec erreur 500
- Message : "PDF introuvable" ou "Le fichier PDF est introuvable sur le serveur"
- Logs : `findPdfPath() - PDF introuvable après X tentatives`

**Cause racine :**
- **Stockage éphémère Railway** : Les fichiers uploadés dans `uploads/` sont perdus lors des redéploiements
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
│  │  │ Serveur Web (Apache/Caddy) ││ │
│  │  │ Document Root: ?          ││ │
│  │  │                           ││ │
│  │  │ Filesystem ÉPHÉMÈRE       ││ │
│  │  │ ❌ uploads/ → perdu        ││ │
│  │  │ ✅ /tmp → persiste         ││ │
│  │  └───────────────────────────┘│ │
│  └───────────────────────────────┘ │
└─────────────────────────────────────┘
```

**Points clés :**
1. **Document Root** : À déterminer via validation (voir section 3)
2. **Stockage éphémère** : Tous les fichiers sauf `/tmp` sont perdus au redéploiement
3. **Routing** : Dépend de la configuration du serveur web (Apache/Caddy/Nginx)

---

## 3. VALIDATION DU DOCUMENT ROOT

**⚠️ IMPORTANT :** Ne pas faire d'hypothèses sur le document root. Valider empiriquement.

### Test 1 : Vérifier que `public/` est servi

```bash
# Tester ping.txt
curl https://cccomputer-production.up.railway.app/ping.txt
```

**Résultat attendu :** `pong`

**Interprétation :**
- ✅ Si `pong` → `public/` est la racine web servie
- ❌ Si 404 → `public/` n'est PAS la racine, ou le fichier n'est pas déployé

### Test 2 : Vérifier le commit déployé

**Railway Dashboard → Service → Shell**

```bash
# Vérifier que les fichiers existent
ls -la /var/www/html/public/test_smtp.php
ls -la /var/www/html/public/ping.txt
ls -la /var/www/html/API/test_smtp.php

# Vérifier le commit déployé
cd /var/www/html
git log -1 --oneline
```

**Interprétation :**
- Si les fichiers existent → Le commit est déployé
- Si les fichiers n'existent pas → Le commit n'est pas déployé ou le chemin est différent

### Test 3 : Déterminer le document root

**Railway Dashboard → Service → Shell**

```bash
# Vérifier DOCUMENT_ROOT
php -r "echo \$_SERVER['DOCUMENT_ROOT'] ?? 'non défini';"

# Vérifier le répertoire de travail
pwd

# Lister les fichiers à la racine
ls -la /var/www/html/
ls -la /app/ 2>/dev/null || echo "/app n'existe pas"
```

**Interprétation :**
- `DOCUMENT_ROOT` indique la racine web réelle
- Comparer avec les chemins des fichiers pour confirmer

---

## 4. POURQUOI 404 SUR `/API/test_smtp.php`

### Causes possibles

#### Cause 1 : Fichier non déployé

**Symptôme :** 404 même après commit et push

**Vérification :**
```bash
# Railway Shell
ls -la /var/www/html/API/test_smtp.php
```

**Solution :**
- Vérifier que le commit est bien poussé : `git log -1`
- Vérifier que Railway a détecté le push (Dashboard → Deployments)
- Attendre la fin du build
- Redéployer manuellement si nécessaire

#### Cause 2 : Document root différent

**Symptôme :** Le fichier existe mais n'est pas accessible via `/API/`

**Vérification :**
```bash
# Railway Shell
php -r "echo \$_SERVER['DOCUMENT_ROOT'];"
ls -la /var/www/html/API/test_smtp.php
```

**Solution :**
- Si `DOCUMENT_ROOT = /var/www/html/public` → Utiliser `/test_smtp.php` (fichier `public/test_smtp.php`)
- Si `DOCUMENT_ROOT = /var/www/html` → Utiliser `/API/test_smtp.php` devrait fonctionner

#### Cause 3 : Routing bloque `/API/`

**Symptôme :** Autres endpoints `/API/` fonctionnent mais pas `test_smtp.php`

**Vérification :**
```bash
# Tester un autre endpoint API
curl https://cccomputer-production.up.railway.app/API/chatroom_get.php
```

**Solution :**
- Si les autres endpoints fonctionnent → Problème spécifique à `test_smtp.php` (vérifier le fichier)
- Si aucun endpoint `/API/` ne fonctionne → Problème de routing (vérifier la configuration serveur)

### Solution recommandée

**Utiliser `/test_smtp.php` (endpoint principal)**

Cet endpoint est plus fiable car :
- Accessible directement à la racine web
- Pas de dépendance au routing `/API/`
- Fonctionne que `public/` soit la racine ou non

---

## 5. SOLUTIONS IMPLÉMENTÉES

### Solution #1 : Endpoint SMTP test fiable

**Endpoint principal recommandé :**
- `public/test_smtp.php` → URL : `/test_smtp.php`

**Endpoint optionnel :**
- `public/API/test_smtp.php` → URL : `/API/test_smtp.php` (si `/API/` est routé)

**Stratégie simplifiée :**
- **Utiliser `/test_smtp.php` en priorité** (endpoint principal)
- `/API/test_smtp.php` est optionnel (fallback si nécessaire)
- Les deux fichiers utilisent les mêmes chemins relatifs (`__DIR__ . '/../'`)

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

## 6. VARIABLES SMTP (CONFIGURATION)

### Variables requises

**Service : `cccomputer` (PAS MySQL)**

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
SMTP_TEST_TOKEN=<générer-un-token-aléatoire>
```

### Explication des variables FROM

**`SMTP_FROM_EMAIL=facturemail@cccomputer.fr` (par défaut)**

- **Pourquoi :** Domaine `cccomputer.fr` validé SPF/DKIM sur Brevo
- **Avantage :** Meilleure délivrabilité, moins de risques de spam
- **Utilisation :** Recommandé pour la production

**`SMTP_FROM_EMAIL=facture@camsongroup.fr` (alternative)**

- **Quand utiliser :** Si le domaine `camsongroup.fr` est validé SPF/DKIM sur Brevo
- **Validation requise :** 
  - SPF : Enregistrement DNS `TXT` pour `camsongroup.fr`
  - DKIM : Clé DKIM configurée dans Brevo Dashboard
- **Vérification :** Brevo Dashboard → Senders → Vérifier le statut de validation

**`SMTP_REPLY_TO=facture@camsongroup.fr`**

- Toujours utiliser `facture@camsongroup.fr` pour les réponses
- Indépendant de `SMTP_FROM_EMAIL`

### Génération du token

```bash
# Linux/Mac
openssl rand -hex 32

# Windows PowerShell
-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | % {[char]$_})
```

---

## 7. FICHIERS FINAUX (CODE COMPLET)

Voir les fichiers suivants dans le projet :
- `public/test_smtp.php` (endpoint principal recommandé)
- `public/API/test_smtp.php` (optionnel)
- `API/factures_envoyer_email.php` (mis à jour avec fallback PDF)
- `API/factures_generate_pdf_content.php` (vérifié)
- `src/Mail/MailerFactory.php` (vérifié)
- `src/Mail/MailerService.php` (vérifié)

---

## 8. SÉCURITÉ

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

## 9. TABLEAU RÉCAPITULATIF : SYMPTÔME → CAUSE → FIX → TEST

| Symptôme | Cause probable | Fix | Test de validation |
|----------|---------------|-----|-------------------|
| **404 sur `/API/test_smtp.php`** | Fichier non déployé | Vérifier commit déployé, redéployer | `curl /test_smtp.php` doit retourner JSON |
| **404 sur `/API/test_smtp.php`** | Document root = `public/` | Utiliser `/test_smtp.php` | `curl /ping.txt` doit retourner `pong` |
| **404 sur `/API/test_smtp.php`** | Routing bloque `/API/` | Utiliser `/test_smtp.php` | `curl /test_smtp.php` doit retourner JSON |
| **403 "Token invalide"** | `SMTP_TEST_TOKEN` manquant/incorrect | Vérifier variable Railway, régénérer token | `curl POST` avec token correct doit retourner `ok: true` |
| **500 "Configuration SMTP invalide"** | Variables SMTP manquantes/incorrectes | Vérifier toutes les variables `SMTP_*` dans Railway | `curl POST` doit retourner `ok: true` |
| **500 "PDF introuvable"** | PDF perdu (stockage éphémère) | Fallback automatique dans `/tmp` | Envoyer facture par email, vérifier logs `[MAIL] regen ok` |
| **Email non reçu** | Credentials SMTP incorrects | Vérifier `SMTP_USERNAME` et `SMTP_PASSWORD` dans Brevo | `curl POST` doit retourner `ok: true`, email reçu |
| **Email rejeté (spam)** | Domaine FROM non validé | Utiliser `facturemail@cccomputer.fr` (validé) | Email reçu dans boîte principale (pas spam) |

---

## 10. NOTES IMPORTANTES

1. **Railway stockage éphémère** : Ne jamais compter sur `uploads/` pour persister
2. **Utiliser `/tmp`** : Toujours disponible, nettoyé automatiquement
3. **Endpoint principal** : Utiliser `/test_smtp.php` (plus fiable que `/API/`)
4. **Logs détaillés** : Tous les logs utilisent des préfixes `[SMTP_TEST]`, `[MAIL]`
5. **Sécurité** : Token obligatoire, validation stricte, pas de secrets exposés
6. **Validation document root** : Toujours valider empiriquement, ne pas faire d'hypothèses

---

**Version :** 1.1  
**Date de mise à jour :** 2025-01-XX  
**Auteur :** Auto (Cursor AI)
