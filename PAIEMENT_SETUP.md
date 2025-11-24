# Configuration du Système de Paiement

## Installation des dépendances

Après avoir ajouté PHPMailer au `composer.json`, exécutez :

```bash
composer install
```

ou

```bash
composer update
```

## Fonctionnalités implémentées

### 1. Génération automatique du justificatif PDF

Lorsqu'un paiement est enregistré, un justificatif de paiement est automatiquement généré en PDF avec :
- Les informations du client
- Les détails du paiement (date, montant, type, référence, IBAN si applicable)
- Un numéro de justificatif unique
- La date d'émission

Le justificatif est :
- **Téléchargé automatiquement** dans le navigateur
- **Enregistré** dans le dossier `/uploads/clients/{client_id}/`
- **Ajouté** aux justificatifs du client (champ pdf1-pdf5)

### 2. Envoi d'email de confirmation

Un email est automatiquement envoyé au client avec :
- Une confirmation de réception du paiement
- La date du paiement
- Le montant
- Le type de paiement
- Le justificatif PDF en pièce jointe
- Le justificatif uploadé par l'utilisateur (si applicable)

### 3. Configuration de l'email

Par défaut, le système utilise la fonction `mail()` de PHP. Pour utiliser un serveur SMTP, modifiez le fichier `includes/email_helper.php` :

```php
// Décommentez et configurez :
$mail->isSMTP();
$mail->Host = 'smtp.example.com';
$mail->SMTPAuth = true;
$mail->Username = 'user@example.com';
$mail->Password = 'password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;
```

Ou utilisez des variables d'environnement :
- `SMTP_HOST` : Serveur SMTP
- `SMTP_USER` : Nom d'utilisateur SMTP
- `SMTP_PASS` : Mot de passe SMTP
- `SMTP_PORT` : Port SMTP (défaut: 587)
- `EMAIL_FROM` : Email de l'expéditeur (défaut: noreply@cccomputer.com)
- `EMAIL_FROM_NAME` : Nom de l'expéditeur (défaut: CCComputer)

## Fichiers créés/modifiés

### Nouveaux fichiers :
- `templates/payment_receipt_template.php` : Template du justificatif PDF
- `API/generate_payment_receipt.php` : API pour générer le justificatif
- `includes/email_helper.php` : Fonctions helper pour l'envoi d'emails

### Fichiers modifiés :
- `composer.json` : Ajout de PHPMailer
- `API/payment_process.php` : Génération et envoi du justificatif
- `public/paiements.php` : Affichage du message de succès avec info email

## Structure du justificatif PDF

Le justificatif contient :
1. **En-tête** : Titre "JUSTIFICATIF DE PAIEMENT"
2. **Informations client** : Raison sociale, numéro client, adresse, email
3. **Détails du paiement** :
   - Date de paiement
   - Montant (en vert, mis en évidence)
   - Type de paiement
   - Référence (si fournie)
   - IBAN (si virement)
   - Notes (si fournies)
4. **Pied de page** : Date d'émission, numéro de justificatif

## Gestion des erreurs

- Si la génération du PDF échoue, le paiement est quand même enregistré (l'erreur est loggée)
- Si l'envoi d'email échoue, le paiement est quand même enregistré (l'erreur est loggée et retournée dans la réponse JSON)
- Les erreurs sont enregistrées dans les logs PHP (`error_log`)

## Personnalisation

### Template du justificatif

Pour personnaliser l'apparence du justificatif, modifiez le fichier :
`templates/payment_receipt_template.php`

Les fonctions disponibles :
- `printReceiptHeader()` : En-tête
- `printReceiptClientInfo()` : Informations client
- `printReceiptPaymentDetails()` : Détails du paiement
- `printReceiptFooter()` : Pied de page

### Template de l'email

Pour personnaliser l'email, modifiez la fonction `generatePaymentConfirmationEmailBody()` dans :
`includes/email_helper.php`

## Notes importantes

1. **Permissions** : Assurez-vous que le dossier `/uploads/clients/` est accessible en écriture
2. **Taille des fichiers** : Les justificatifs uploadés sont limités à 10 Mo
3. **Formats acceptés** : PDF, JPG, PNG pour les justificatifs uploadés
4. **Validation email** : L'email n'est envoyé que si l'adresse email du client est valide

