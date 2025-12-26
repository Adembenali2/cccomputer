# Analyse : Envoi Automatique de Factures par Email

**Date :** 2025-01-XX  
**Lead Dev :** Auto (Cursor AI)

---

## 1. ÉTAT ACTUEL DU SYSTÈME

### ✅ Tables existantes

#### Table `factures`
- ✅ `id`, `numero`, `date_facture`, `montant_ttc`
- ✅ `email_envoye` (tinyint) - Indicateur d'envoi
- ✅ `date_envoi_email` (datetime) - Date d'envoi
- ✅ `pdf_path` (varchar) - Chemin du PDF
- ✅ `pdf_genere` (tinyint) - PDF généré ou non
- ✅ `statut` (enum: brouillon, envoyee, payee, en_retard, annulee)
- ✅ `id_client` (FK vers `clients`)

#### Table `clients`
- ✅ `id`, `raison_sociale`, `email` (varchar 255)
- ✅ `adresse`, `code_postal`, `ville`, `siret`

#### Table `paiements`
- ✅ `id`, `id_facture` (FK), `id_client` (FK)
- ✅ `montant`, `date_paiement`, `mode_paiement`, `statut`
- ✅ `email_envoye` (tinyint) - Pour les reçus de paiement
- ✅ `date_envoi_email` (datetime)

### ✅ Système d'authentification
- ✅ `includes/auth.php` - Vérification session + rôles
- ✅ Table `utilisateurs` avec rôles (Admin, Dirigeant, Secrétaire, etc.)
- ✅ `currentUserId()` helper disponible

### ✅ Configuration d'environnement
- ✅ `config/app.php` - Configuration centralisée
- ✅ Variables Railway supportées :
  - `SMTP_ENABLED`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`
  - `SMTP_USERNAME`, `SMTP_PASSWORD`
  - `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME`, `SMTP_REPLY_TO`

### ✅ Système d'email existant
- ✅ **PHPMailer** installé (composer.json)
- ✅ `src/Mail/MailerService.php` - Service d'envoi avec PDF
- ✅ `src/Mail/MailerFactory.php` - Factory pour PHPMailer
- ✅ `src/Mail/MailerException.php` - Exceptions personnalisées
- ✅ Endpoint manuel : `API/factures_envoyer_email.php`
- ✅ Test SMTP : `public/test_smtp.php`

### ✅ Génération PDF
- ✅ **TCPDF** installé (composer.json)
- ✅ `API/factures_generate_pdf_content.php` - Fonction `generateInvoicePdf()`
- ✅ Fallback vers `/tmp` pour Railway (stockage éphémère)
- ✅ Génération à la volée si PDF perdu

### ✅ Routing PHP
- ✅ `index.php` - Router principal (gère `/API/` et fichiers statiques)
- ✅ `router.php` - Router pour serveur PHP intégré
- ✅ Endpoints API dans `/API/`
- ✅ Helpers : `includes/api_helpers.php` (jsonResponse, getPdo, etc.)

### ✅ Gestion des erreurs
- ✅ `includes/ErrorHandler.php`
- ✅ `includes/Logger.php`
- ✅ Logs via `error_log()` partout
- ✅ Sentry configuré (`config/sentry.php`)

---

## 2. ARCHITECTURE PROPOSÉE : "Invoice by Email"

### 2.1 Moments d'envoi (stratégie)

**Option A : Après génération de facture** ⭐ RECOMMANDÉ
- ✅ Déclenchement : Après `factures_generer.php` (création facture)
- ✅ Condition : `statut = 'brouillon'` ET `pdf_genere = 1`
- ✅ Avantage : Client reçoit immédiatement la facture
- ⚠️ Risque : Envoi même si facture non validée

**Option B : Après validation admin**
- ✅ Déclenchement : Quand `statut` passe de `brouillon` → `envoyee`
- ✅ Condition : Admin valide la facture
- ✅ Avantage : Contrôle qualité avant envoi
- ⚠️ Risque : Processus manuel supplémentaire

**Option C : Après paiement**
- ✅ Déclenchement : Quand `paiements.statut = 'recu'`
- ✅ Condition : Paiement enregistré pour une facture
- ✅ Avantage : Confirmation de paiement
- ⚠️ Risque : Facture déjà envoyée avant paiement

**Option D : Manuel depuis dashboard** ✅ DÉJÀ IMPLÉMENTÉ
- ✅ Endpoint : `API/factures_envoyer_email.php`
- ✅ Utilisation : Bouton "Envoyer par email" dans l'interface

**🎯 RECOMMANDATION : Option A + Option D (hybride)**
- Envoi automatique après génération (si config activé)
- Possibilité d'envoi manuel depuis dashboard (toujours disponible)
- Variable Railway : `AUTO_SEND_INVOICES=true/false`

### 2.2 Modèle de données (déjà existant)

```sql
-- Table factures (déjà présente)
email_envoye TINYINT(1) DEFAULT 0
date_envoi_email DATETIME NULL
pdf_path VARCHAR(255) NULL
pdf_genere TINYINT(1) DEFAULT 0

-- Table email_logs (À CRÉER pour traçabilité)
CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facture_id INT NULL,
    type_email ENUM('facture', 'paiement', 'autre') NOT NULL,
    destinataire VARCHAR(255) NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    statut ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE SET NULL
);
```

### 2.3 Stockage PDF

**Stratégie actuelle (déjà implémentée) :**
- ✅ Génération dans `uploads/factures/YYYY/`
- ✅ Fallback vers `/tmp` si fichier perdu (Railway)
- ✅ Régénération à la volée si PDF introuvable
- ✅ Nettoyage automatique des PDFs temporaires

**Recommandation :** Conserver cette stratégie (fonctionne bien sur Railway)

### 2.4 Logs et traçabilité

**À implémenter :**
- ✅ Table `email_logs` pour journaliser tous les envois
- ✅ Logs de succès/erreur avec `message_id` (PHPMailer)
- ✅ Retry logic pour échecs temporaires
- ✅ Idempotence : vérifier `email_envoye = 1` avant envoi

### 2.5 Idempotence

**Protection contre double envoi :**
- ✅ Vérifier `email_envoye = 1` avant envoi
- ✅ Transaction DB pour atomicité
- ✅ Lock sur `factures.id` pendant l'envoi
- ✅ Variable Railway : `AUTO_SEND_INVOICES_RETRY=false` (désactiver retry automatique)

---

## 3. PLAN D'IMPLÉMENTATION

### 3.1 Variables Railway à créer

**Service : `cccomputer` (PAS MySQL)**

```bash
# Activation envoi automatique
AUTO_SEND_INVOICES=true

# Configuration SMTP (déjà existantes)
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@brevo.com
SMTP_PASSWORD=votre-password-brevo
SMTP_FROM_EMAIL=facturemail@cccomputer.fr
SMTP_FROM_NAME=Camson Group - Facturation
SMTP_REPLY_TO=facture@camsongroup.fr

# Options avancées (optionnelles)
AUTO_SEND_INVOICES_RETRY=false  # Désactiver retry automatique
AUTO_SEND_INVOICES_DELAY=0       # Délai en secondes avant envoi (0 = immédiat)
```

### 3.2 Fichiers à créer/modifier

#### Nouveaux fichiers

1. **`src/Services/InvoiceEmailService.php`**
   - Service centralisé pour l'envoi automatique
   - Gestion idempotence, logs, retry

2. **`src/Mail/templates/invoice_email.html`**
   - Template HTML pour email de facture
   - Version texte automatique (strip_tags)

3. **`sql/migrations/create_email_logs_table.sql`**
   - Migration pour table `email_logs`

4. **`sql/run_migration_email_logs.php`**
   - Script PHP pour exécuter la migration

#### Fichiers à modifier

1. **`API/factures_generer.php`**
   - Ajouter appel à `InvoiceEmailService::sendInvoiceAfterGeneration()`
   - Condition : `AUTO_SEND_INVOICES=true`

2. **`API/factures_update_statut.php`**
   - Ajouter envoi si `statut = 'envoyee'` ET `email_envoye = 0`

3. **`API/paiements_enregistrer.php`**
   - Optionnel : Envoi facture si paiement complet

4. **`config/app.php`**
   - Ajouter config `auto_send_invoices`

### 3.3 Endpoints/handlers

**Endpoints existants (à conserver) :**
- ✅ `POST /API/factures_envoyer_email.php` - Envoi manuel

**Nouveaux endpoints (optionnels) :**
- `POST /API/invoices/:id/send` - Alias pour envoi manuel
- `GET /API/email_logs` - Liste des logs d'envoi (admin)

### 3.4 Migrations SQL

**Migration 1 : Table `email_logs`**
```sql
CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facture_id INT NULL,
    type_email ENUM('facture', 'paiement', 'autre') NOT NULL,
    destinataire VARCHAR(255) NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    statut ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_facture_id (facture_id),
    INDEX idx_statut (statut),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 4. IMPLÉMENTATION CONCRÈTE

### 4.1 Solution email choisie

**✅ PHPMailer via SMTP (déjà implémenté)**

**Pourquoi :**
- ✅ Déjà installé et configuré
- ✅ Fonctionne avec Brevo (SMTP)
- ✅ Support PDF en pièce jointe
- ✅ Gestion d'erreurs robuste
- ✅ Logs détaillés

**Alternative (non recommandée) :**
- SendGrid/Mailgun/Resend API : Nécessiterait refactoring complet

### 4.2 Service `InvoiceEmailService.php`

**Responsabilités :**
- Envoi automatique après génération facture
- Vérification idempotence (`email_envoye = 1`)
- Logs dans `email_logs`
- Gestion erreurs avec retry (optionnel)
- Template HTML + texte

### 4.3 Template email

**Structure :**
- Header : Logo CC Computer
- Corps : Message personnalisé avec détails facture
- Footer : Coordonnées, mentions légales
- Pièce jointe : PDF facture

### 4.4 Exemple d'envoi avec PDF

**Déjà implémenté dans `MailerService::sendEmailWithPdf()`**
- ✅ Validation PDF (existe, lisible, taille < 10MB)
- ✅ Attachement base64
- ✅ Nettoyage fichier temporaire après envoi

---

## 5. QUESTIONS TECHNIQUES RÉSOLUES

### 5.1 Structure de routing PHP

**Réponse :**
- ✅ `index.php` gère le routing principal
- ✅ Bypass explicite pour `/test_smtp.php` et `/API/`
- ✅ Fichiers statiques servis depuis `public/`
- ✅ Endpoints API dans `/API/` (inclus directement)

### 5.2 Configuration

**Réponse :**
- ✅ `config/app.php` - Configuration centralisée
- ✅ Variables d'environnement via `$_ENV` / `getenv()`
- ✅ Pas de `.env` (Railway utilise variables d'environnement)

### 5.3 Dépendances

**Réponse :**
- ✅ Composer installé (`composer.json`, `composer.lock`)
- ✅ Autoload PSR-4 : `App\` → `app/` et `src/`
- ✅ Dépendances : PHPMailer, TCPDF, Monolog, Sentry

### 5.4 Gestion des erreurs

**Réponse :**
- ✅ `error_log()` pour logs serveur
- ✅ `includes/Logger.php` (Monolog)
- ✅ `includes/ErrorHandler.php`
- ✅ Sentry pour monitoring production

---

## 6. POINT DE DÉPART

### Fichier à créer en premier

**`src/Services/InvoiceEmailService.php`**

Ce service centralisera toute la logique d'envoi automatique et pourra être appelé depuis :
- `API/factures_generer.php` (après génération)
- `API/factures_update_statut.php` (après validation)
- `API/paiements_enregistrer.php` (après paiement)

### Checklist de tests

#### Tests développement (local)

- [ ] Migration `email_logs` exécutée
- [ ] Variable `AUTO_SEND_INVOICES=true` dans `.env` local
- [ ] Générer une facture → Vérifier envoi automatique
- [ ] Vérifier `email_logs` contient l'entrée
- [ ] Vérifier `factures.email_envoye = 1`
- [ ] Tester idempotence (double génération → 1 seul envoi)
- [ ] Tester avec email invalide → Vérifier logs d'erreur
- [ ] Tester avec PDF manquant → Vérifier régénération

#### Tests production (Railway)

- [ ] Variables Railway configurées (Service `cccomputer`)
- [ ] `SMTP_ENABLED=true` et credentials valides
- [ ] `AUTO_SEND_INVOICES=true`
- [ ] Générer facture test → Vérifier réception email
- [ ] Vérifier logs Railway (erreurs SMTP)
- [ ] Vérifier `email_logs` en DB
- [ ] Tester retry si échec temporaire

### Pièges Railway

1. **Stockage éphémère**
   - ✅ Déjà géré : Fallback vers `/tmp` si PDF perdu
   - ✅ Régénération à la volée

2. **Variables d'environnement**
   - ⚠️ Service correct : `cccomputer` (PAS MySQL)
   - ⚠️ Redéploiement nécessaire après ajout variable

3. **Ports**
   - ✅ SMTP port 587 (TLS) - Standard, pas de config spéciale

4. **Filesystem**
   - ✅ `/tmp` toujours disponible
   - ❌ `uploads/` perdu au redéploiement (déjà géré)

5. **Timeouts**
   - ⚠️ SMTP timeout : 30s par défaut (PHPMailer)
   - ⚠️ Railway timeout : 60s max pour requête HTTP
   - ✅ Solution : Envoi asynchrone si nécessaire (queue)

6. **Logs**
   - ✅ `error_log()` → Logs Railway visibles dans Dashboard
   - ✅ Table `email_logs` pour traçabilité

---

## 7. PROCHAINES ÉTAPES

1. ✅ Créer `src/Services/InvoiceEmailService.php`
2. ✅ Créer template email HTML
3. ✅ Créer migration `email_logs`
4. ✅ Modifier `API/factures_generer.php` pour envoi automatique
5. ✅ Modifier `config/app.php` pour config auto-send
6. ✅ Tests locaux
7. ✅ Déploiement Railway
8. ✅ Tests production

---

**Version :** 1.0  
**Statut :** Prêt pour implémentation

