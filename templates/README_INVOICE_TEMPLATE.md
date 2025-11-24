# Modèle de Facture Personnalisable

Ce dossier contient le modèle de facture que vous pouvez personnaliser selon vos besoins.

## Fichier du modèle

Le fichier `invoice_template.php` contient toutes les fonctions pour générer votre facture.

## Variables disponibles

Dans le modèle, vous avez accès à deux variables principales :

### `$client`
Contient toutes les informations du client :
- `id` : ID du client
- `name` : Nom du client
- `numero_client` : Numéro de client
- Et toutes les autres données du client

### `$invoice`
Contient toutes les informations de la facture :
- `invoice_number` : Numéro de facture (ex: FAC-20241220-00001)
- `invoice_date` : Date de facturation (format YYYY-MM-DD)
- `due_date` : Date d'échéance (format YYYY-MM-DD)
- `period_start` : Début de période (format YYYY-MM-DD)
- `period_end` : Fin de période (format YYYY-MM-DD)
- `nb_pages` : Nombre de pages noir et blanc
- `nb_amount` : Montant noir et blanc (en euros)
- `color_pages` : Nombre de pages couleur
- `color_amount` : Montant couleur (en euros)
- `total_pages` : Total pages
- `total_amount` : Montant total (en euros)
- `status` : Statut ('paid', 'pending', 'overdue')

### `$pdf`
Instance TCPDF pour personnaliser le PDF.

## Fonctions à personnaliser

Le modèle contient 4 fonctions principales que vous pouvez modifier :

1. **`printHeader($pdf, $client, $invoice)`** : Affiche l'en-tête (logo, informations entreprise, numéro de facture)
2. **`printClientInfo($pdf, $client)`** : Affiche les informations du client
3. **`printInvoiceDetails($pdf, $invoice)`** : Affiche le détail de la facture (tableau avec consommation)
4. **`printFooter($pdf, $invoice)`** : Affiche le pied de page

## Documentation TCPDF

Pour personnaliser davantage, consultez la documentation TCPDF :
- https://tcpdf.org/
- https://tcpdf.org/docs/

## Exemples de personnalisation

### Ajouter un logo
```php
$logoPath = __DIR__ . '/../assets/images/logo.png';
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 15, 15, 40, 0, 'PNG');
}
```

### Changer les couleurs
```php
$pdf->SetTextColor(255, 0, 0); // Rouge
$pdf->SetFillColor(240, 240, 240); // Gris clair
```

### Ajouter du texte
```php
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetXY(15, 100);
$pdf->Cell(0, 10, 'Votre texte ici', 0, 1);
```

## Sauvegarde

Après avoir modifié le modèle, sauvegardez le fichier `invoice_template.php`. 
Les modifications seront prises en compte lors de la prochaine génération de facture.

