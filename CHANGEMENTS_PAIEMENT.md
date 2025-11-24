# Changements Majeurs - Système de Paiement

## Résumé des modifications

### 1. **Création de la table `paiements`**
   - **Fichier**: `sql/migration_create_paiements_table.sql`
   - **Objectif**: Stocker l'historique des paiements dans la base de données
   - **Colonnes principales**:
     - Informations du paiement (montant, type, date, référence, IBAN, notes)
     - Chemins des justificatifs (upload et PDF généré)
     - Numéro de justificatif unique
     - Lien avec le client et l'utilisateur

### 2. **Simplification de `payment_process.php`**
   - **Suppression**: Code d'envoi d'email (PHPMailer) pour la phase de test
   - **Ajout**: 
     - Génération du justificatif PDF
     - Sauvegarde locale dans `/receipts/` (dossier du projet)
     - Sauvegarde web dans `/uploads/clients/{id}/`
     - Enregistrement dans la table `paiements`
   - **Correction**: Gestion d'erreurs améliorée pour ne pas bloquer l'enregistrement

### 3. **Mise à jour de `paiements.php`**
   - **Historique**: Récupération depuis la base de données au lieu de données mock
   - **Affichage**: Nouvelle colonne "Justificatif" dans le tableau avec liens de téléchargement
   - **Message de succès**: Affiche le numéro de justificatif généré

### 4. **Nouveau fichier `get_payments_history.php`**
   - **Objectif**: API pour récupérer l'historique des paiements
   - **Fonctionnalités**: Filtrage par client, tri par date

### 5. **Styles CSS**
   - **Ajout**: Styles pour les boutons de téléchargement de justificatifs
   - **Fichier**: `assets/css/paiements.css`

## Installation

1. **Exécuter la migration SQL**:
   ```sql
   SOURCE sql/migration_create_paiements_table.sql;
   ```
   Ou importer le fichier via phpMyAdmin/MySQL Workbench

2. **Créer le dossier local pour les justificatifs** (optionnel, créé automatiquement):
   ```
   /receipts/
   ```

## Fonctionnement

1. **Enregistrement d'un paiement**:
   - Le justificatif PDF est généré automatiquement
   - Sauvegardé dans `/receipts/` (local) et `/uploads/clients/{id}/` (web)
   - Enregistré dans la table `paiements` avec toutes les informations
   - Téléchargement automatique dans le navigateur

2. **Affichage dans l'historique**:
   - Les paiements sont récupérés depuis la base de données
   - Chaque paiement affiche un lien vers son justificatif PDF
   - Filtrage possible par client

## Structure des fichiers

```
/receipts/                          # Dossier local pour les justificatifs (phase de test)
  └── JUST-YYYYMMDD-XXXXX-XXXXX.pdf

/uploads/clients/{client_id}/        # Dossier web pour accès via navigateur
  └── YYYYMMDD_HHMMSS_justificatif_paiement_{id}.pdf

/sql/
  └── migration_create_paiements_table.sql

/API/
  ├── payment_process.php           # Traitement du paiement (modifié)
  ├── generate_payment_receipt.php   # Génération PDF (existant)
  └── get_payments_history.php      # Nouveau: API historique

/public/
  └── paiements.php                 # Page principale (modifiée)
```

## Notes importantes

- **Phase de test**: L'envoi d'email est désactivé
- **Dossier local**: Les justificatifs sont sauvegardés dans `/receipts/` pour un accès facile
- **Base de données**: La table `paiements` doit être créée avant utilisation
- **Gestion d'erreurs**: Les erreurs de génération PDF n'empêchent pas l'enregistrement du paiement

