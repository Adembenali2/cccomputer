# Configuration SMTP avec PHPMailer

Ce guide explique comment configurer l'envoi d'emails via SMTP en utilisant PHPMailer.

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
SMTP_FROM_EMAIL=noreply@cccomputer.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=noreply@cccomputer.fr
```

### Exemples de configuration pour différents services

#### Gmail / Google Workspace

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

**Note pour Gmail** : Vous devez utiliser un "Mot de passe d'application" et non votre mot de passe Gmail normal. Activez la validation en 2 étapes, puis créez un mot de passe d'application dans les paramètres de votre compte Google.

#### SendGrid

```bash
SMTP_ENABLED=true
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=apikey
SMTP_PASSWORD=votre-clé-api-sendgrid
SMTP_FROM_EMAIL=noreply@cccomputer.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=noreply@cccomputer.fr
```

#### Mailgun

```bash
SMTP_ENABLED=true
SMTP_HOST=smtp.mailgun.org
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=postmaster@votre-domaine.mailgun.org
SMTP_PASSWORD=votre-mot-de-passe-mailgun
SMTP_FROM_EMAIL=noreply@cccomputer.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=noreply@cccomputer.fr
```

#### AWS SES (Simple Email Service)

```bash
SMTP_ENABLED=true
SMTP_HOST=email-smtp.region.amazonaws.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-access-key-id
SMTP_PASSWORD=votre-secret-access-key
SMTP_FROM_EMAIL=noreply@cccomputer.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=noreply@cccomputer.fr
```

#### OVH / Autres hébergeurs

```bash
SMTP_ENABLED=true
SMTP_HOST=ssl0.ovh.net
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@votre-domaine.fr
SMTP_PASSWORD=votre-mot-de-passe
SMTP_FROM_EMAIL=noreply@cccomputer.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=noreply@cccomputer.fr
```

## Configuration sur Railway

1. Allez dans votre projet Railway
2. Cliquez sur "Variables" dans le menu
3. Ajoutez toutes les variables d'environnement listées ci-dessus
4. Redéployez votre application

## Configuration en local (XAMPP)

Créez un fichier `.env` à la racine du projet (ou configurez dans votre serveur web) :

```env
SMTP_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@gmail.com
SMTP_PASSWORD=votre-mot-de-passe
SMTP_FROM_EMAIL=noreply@cccomputer.fr
SMTP_FROM_NAME=CC Computer
SMTP_REPLY_TO=noreply@cccomputer.fr
```

**Note** : Si vous utilisez un fichier `.env`, vous devrez peut-être charger ces variables dans votre code PHP. Vous pouvez utiliser une bibliothèque comme `vlucas/phpdotenv` ou les charger manuellement.

## Test de la configuration

Une fois les variables configurées, testez l'envoi d'email depuis l'interface "Facture Mail" dans la page des paiements.

## Dépannage

### Erreur "SMTP n'est pas configuré"
- Vérifiez que `SMTP_ENABLED=true` est défini
- Vérifiez que `SMTP_HOST` est défini et non vide

### Erreur d'authentification
- Vérifiez que `SMTP_USERNAME` et `SMTP_PASSWORD` sont corrects
- Pour Gmail, utilisez un mot de passe d'application
- Vérifiez que le port et la sécurité (tls/ssl) sont corrects

### Erreur de connexion
- Vérifiez que le port SMTP n'est pas bloqué par un firewall
- Vérifiez que `SMTP_HOST` est correct
- Essayez avec `SMTP_SECURE=ssl` et `SMTP_PORT=465` si `tls` ne fonctionne pas

### Emails non reçus
- Vérifiez les spams
- Vérifiez les logs du serveur pour voir les erreurs PHPMailer
- Activez le mode debug temporairement (décommentez les lignes dans le code)

## Sécurité

⚠️ **Important** : Ne commitez jamais vos mots de passe SMTP dans le code source. Utilisez toujours des variables d'environnement.

