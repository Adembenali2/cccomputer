# Configuration SMTP avec PHPMailer

Ce guide explique comment configurer l'envoi d'emails via SMTP en utilisant PHPMailer pour l'envoi de factures.

## Variables d'environnement requises

Pour activer l'envoi d'emails via SMTP, vous devez configurer les variables d'environnement suivantes :

### Variables obligatoires

```bash
SMTP_ENABLED=true
SMTP_HOST=smtp.votre-service.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@exemple.com
SMTP_PASSWORD=votre-mot-de-passe
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

**Important** : L'adresse `facture@camsongroup.fr` doit être autorisée par votre fournisseur SMTP. Si vous utilisez un service externe (Gmail, SendGrid, etc.), vous devrez configurer les enregistrements DNS (SPF, DKIM, DMARC) pour autoriser l'envoi depuis ce domaine.

## Configuration sur Railway

### 1. Ajouter les variables d'environnement

1. Allez dans votre projet Railway
2. Cliquez sur "Variables" dans le menu
3. Ajoutez toutes les variables d'environnement listées ci-dessus (une variable par ligne)
4. Redéployez votre application

### 2. Format des variables sur Railway

Railway accepte les variables d'environnement au format suivant (une par ligne) :

```
SMTP_ENABLED=true
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=apikey
SMTP_PASSWORD=SG.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

## Exemples de configuration pour différents services

### Gmail / Google Workspace

⚠️ **Attention** : Gmail n'est **pas recommandé** pour la production sur Railway car :
- Il nécessite un compte Google avec validation en 2 étapes
- Il nécessite un "Mot de passe d'application" (pas votre mot de passe normal)
- L'adresse FROM doit correspondre au compte Gmail (vous ne pouvez pas utiliser `facture@camsongroup.fr` avec un compte Gmail standard)
- Les limites d'envoi sont restrictives (500 emails/jour pour un compte gratuit)

Si vous devez utiliser Gmail :

```bash
SMTP_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@gmail.com
SMTP_PASSWORD=votre-mot-de-passe-application
SMTP_FROM_EMAIL=votre-email@gmail.com
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=votre-email@gmail.com
```

**Note** : Pour utiliser `facture@camsongroup.fr` avec Gmail, vous devez :
1. Configurer Google Workspace pour le domaine `camsongroup.fr`
2. Créer un compte `facture@camsongroup.fr` dans Google Workspace
3. Configurer les enregistrements DNS (SPF, DKIM, DMARC) pour le domaine

### Brevo (anciennement Sendinblue) - Recommandé pour production

Brevo est un service transactionnel fiable avec un plan gratuit généreux (300 emails/jour) :

```bash
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@brevo.com
SMTP_PASSWORD=votre-clé-api-brevo
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

**Configuration DNS requise** :
- Ajoutez les enregistrements SPF, DKIM et DMARC fournis par Brevo dans votre DNS
- Vérifiez le domaine `camsongroup.fr` dans le tableau de bord Brevo

### Mailjet - Recommandé pour production

Mailjet offre un plan gratuit (200 emails/jour) et supporte l'envoi depuis un domaine personnalisé :

```bash
SMTP_ENABLED=true
SMTP_HOST=in-v3.mailjet.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-api-key
SMTP_PASSWORD=votre-secret-key
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

**Configuration DNS requise** :
- Configurez le domaine `camsongroup.fr` dans Mailjet
- Ajoutez les enregistrements SPF, DKIM et DMARC fournis

### SendGrid - Recommandé pour production

SendGrid offre un plan gratuit (100 emails/jour) :

```bash
SMTP_ENABLED=true
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=apikey
SMTP_PASSWORD=SG.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

**Configuration DNS requise** :
- Vérifiez le domaine `camsongroup.fr` dans SendGrid
- Ajoutez les enregistrements SPF, DKIM et DMARC fournis

### AWS SES (Simple Email Service) - Recommandé pour production

AWS SES est très fiable et économique pour les volumes importants :

```bash
SMTP_ENABLED=true
SMTP_HOST=email-smtp.eu-west-1.amazonaws.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-access-key-id
SMTP_PASSWORD=votre-secret-access-key
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

**Configuration DNS requise** :
- Vérifiez le domaine `camsongroup.fr` dans AWS SES
- Ajoutez les enregistrements SPF, DKIM et DMARC fournis

### OVH / Autres hébergeurs

Si vous avez un hébergement OVH avec un compte email :

```bash
SMTP_ENABLED=true
SMTP_HOST=ssl0.ovh.net
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=facture@camsongroup.fr
SMTP_PASSWORD=votre-mot-de-passe-email
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

## Configuration DNS (SPF, DKIM, DMARC)

Pour que les emails envoyés depuis `facture@camsongroup.fr` soient acceptés et ne soient pas marqués comme spam, vous devez configurer les enregistrements DNS suivants :

### SPF (Sender Policy Framework)

Ajoutez un enregistrement TXT dans votre DNS pour le domaine `camsongroup.fr` :

```
v=spf1 include:_spf.votre-service.com ~all
```

Remplacez `_spf.votre-service.com` par l'enregistrement SPF fourni par votre service SMTP (Brevo, Mailjet, SendGrid, etc.).

### DKIM (DomainKeys Identified Mail)

Ajoutez les enregistrements DKIM fournis par votre service SMTP. Ce sont généralement des enregistrements TXT avec des clés publiques.

### DMARC (Domain-based Message Authentication)

Ajoutez un enregistrement TXT pour `_dmarc.camsongroup.fr` :

```
v=DMARC1; p=quarantine; rua=mailto:admin@camsongroup.fr
```

## Configuration en local (XAMPP)

Créez un fichier `.env` à la racine du projet (ou configurez dans votre serveur web) :

```env
SMTP_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@gmail.com
SMTP_PASSWORD=votre-mot-de-passe
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=facture@camsongroup.fr
```

**Note** : Si vous utilisez un fichier `.env`, vous devrez peut-être charger ces variables dans votre code PHP. Vous pouvez utiliser une bibliothèque comme `vlucas/phpdotenv` ou les charger manuellement.

## Test de la configuration

### Via l'interface

Une fois les variables configurées, testez l'envoi d'email depuis l'interface "Facture Mail" dans la page des paiements.

### Via l'API de test (protégée)

Un endpoint de test est disponible : `API/test_smtp.php`

**⚠️ IMPORTANT** : Cet endpoint est protégé par un token. Définissez la variable d'environnement `SMTP_TEST_TOKEN` avec une valeur secrète.

**Utilisation** :

```bash
curl -X POST https://votre-domaine.com/API/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{
    "token": "votre-token-secret",
    "to": "test@exemple.com"
  }'
```

**Réponse en cas de succès** :

```json
{
  "ok": true,
  "message": "Email de test envoyé avec succès",
  "to": "test@exemple.com"
}
```

**Réponse en cas d'erreur** :

```json
{
  "ok": false,
  "error": "Description de l'erreur"
}
```

## Dépannage

### Erreur "SMTP n'est pas configuré"

- Vérifiez que `SMTP_ENABLED=true` est défini
- Vérifiez que `SMTP_HOST` est défini et non vide
- Vérifiez que `SMTP_USERNAME` et `SMTP_PASSWORD` sont définis

### Erreur d'authentification

- Vérifiez que `SMTP_USERNAME` et `SMTP_PASSWORD` sont corrects
- Pour Gmail, utilisez un mot de passe d'application (pas votre mot de passe normal)
- Vérifiez que le port et la sécurité (tls/ssl) sont corrects
- Vérifiez que votre compte n'est pas bloqué ou suspendu

### Erreur de connexion

- Vérifiez que le port SMTP n'est pas bloqué par un firewall
- Vérifiez que `SMTP_HOST` est correct
- Essayez avec `SMTP_SECURE=ssl` et `SMTP_PORT=465` si `tls` ne fonctionne pas
- Sur Railway, vérifiez que les ports sortants ne sont pas bloqués

### Emails non reçus ou marqués comme spam

- Vérifiez les spams/courrier indésirable
- Vérifiez les logs du serveur pour voir les erreurs PHPMailer
- Vérifiez que les enregistrements DNS (SPF, DKIM, DMARC) sont correctement configurés
- Vérifiez que l'adresse FROM (`facture@camsongroup.fr`) est autorisée par votre service SMTP
- Utilisez un service transactionnel (Brevo, Mailjet, SendGrid) plutôt que Gmail pour la production

### Erreur "Le FROM doit être autorisé par le provider SMTP"

Si vous utilisez un service SMTP externe (Gmail, SendGrid, etc.) avec l'adresse `facture@camsongroup.fr`, vous devez :

1. **Vérifier le domaine** dans le tableau de bord de votre service SMTP
2. **Configurer les enregistrements DNS** (SPF, DKIM, DMARC) fournis par le service
3. **Attendre la propagation DNS** (peut prendre jusqu'à 48h)

Si le problème persiste, utilisez temporairement l'adresse email de votre compte SMTP comme FROM, puis basculez vers `facture@camsongroup.fr` une fois le domaine vérifié.

## Sécurité

⚠️ **Important** : 
- Ne commitez jamais vos mots de passe SMTP dans le code source
- Utilisez toujours des variables d'environnement
- Ne partagez jamais vos tokens/clés API
- Utilisez des mots de passe d'application pour Gmail (pas votre mot de passe principal)
- Désactivez ou protégez l'endpoint de test (`API/test_smtp.php`) en production

## Recommandations pour la production

1. **Utilisez un service transactionnel** : Brevo, Mailjet, SendGrid ou AWS SES plutôt que Gmail
2. **Configurez les DNS** : SPF, DKIM et DMARC pour améliorer la délivrabilité
3. **Utilisez un domaine vérifié** : `facture@camsongroup.fr` avec le domaine vérifié dans votre service SMTP
4. **Surveillez les logs** : Vérifiez régulièrement les logs d'envoi pour détecter les problèmes
5. **Testez régulièrement** : Utilisez l'endpoint de test pour vérifier que la configuration fonctionne
