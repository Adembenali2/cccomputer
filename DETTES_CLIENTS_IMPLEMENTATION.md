# 💰 Implémentation de la section "Dettes clients"

## 📁 Fichiers créés/modifiés

### 1. **API Backend**
- **Fichier** : `API/paiements_dettes.php` (NOUVEAU)
- **Description** : API pour calculer les dettes mensuelles selon les règles de tarification

### 2. **Page Frontend**
- **Fichier** : `public/paiements_dettes.php` (NOUVEAU)
- **Description** : Page dédiée pour afficher les dettes des clients

### 3. **Styles CSS**
- **Fichier** : `assets/css/paiements_dettes.css` (NOUVEAU)
- **Description** : Styles pour la page des dettes

### 4. **Modifications**
- **Fichier** : `public/paiements.php` (MODIFIÉ)
- **Description** : Ajout d'un lien vers la page des dettes

- **Fichier** : `assets/css/paiements.css` (MODIFIÉ)
- **Description** : Styles pour le header avec bouton

---

## 🧮 Règles de tarification implémentées

### Noir & Blanc
- **Prix HT** : 0.05 € par page
- **Prix TTC** : 0.06 € par page
- **Pas de réduction** : Chaque copie compte, même au-delà de 1000 copies/mois

### Couleur
- **Prix HT** : 0.09 € par page
- **Prix TTC** : 0.11 € par page
- **Pas de réduction** : Chaque copie compte dès la première

---

## 📆 Période comptable

### Règle : Du 20 au 20

La période comptable fonctionne ainsi :
- **Début** : Le 20 du mois sélectionné
- **Fin** : Le 20 du mois suivant

**Exemple** :
- Mois sélectionné : Janvier 2024
- Période : 20 janvier 2024 → 20 février 2024

### Gestion des relevés

- Si un relevé existe le 20 : on utilise ce relevé
- Si aucun relevé le 20 : on utilise le **dernier relevé disponible** avant le 20

---

## 📊 Calcul de la consommation

### Principe

Pour chaque photocopieur (MAC) :

1. **Compteur de départ** = Premier compteur enregistré (toutes dates confondues, toutes tables)
2. **Compteur fin période** = Dernier compteur dans la période (20 → 20) ou dernier disponible
3. **Consommation** = Compteur fin - Compteur départ

### Formule

```
Consommation N&B = Compteur fin N&B - Compteur départ N&B
Consommation Couleur = Compteur fin Couleur - Compteur départ Couleur
```

### Montants

```
Montant N&B HT = Consommation N&B × 0.05 €
Montant N&B TTC = Consommation N&B × 0.06 €
Montant Couleur HT = Consommation Couleur × 0.09 €
Montant Couleur TTC = Consommation Couleur × 0.11 €

Total HT = Montant N&B HT + Montant Couleur HT
Total TTC = Montant N&B TTC + Montant Couleur TTC
```

---

## 📋 Données affichées par client

Pour chaque client, la page affiche :

### Informations client
- Nom du client (raison sociale)
- Numéro de client

### Pour chaque photocopieur associé
- **Modèle** du photocopieur
- **MAC adresse**
- **Compteur départ N&B** : Premier compteur global
- **Compteur départ Couleur** : Premier compteur global
- **Compteur fin N&B** : Compteur à la fin de la période
- **Compteur fin Couleur** : Compteur à la fin de la période
- **Consommation N&B** : Nombre de pages consommées
- **Consommation Couleur** : Nombre de pages consommées
- **Montant N&B TTC** : Montant facturé pour N&B
- **Montant Couleur TTC** : Montant facturé pour Couleur
- **Total TTC** : Total pour ce photocopieur

### Totaux par client
- **Total HT** : Total hors taxes pour tous les photocopieurs
- **Total TTC** : Total toutes taxes comprises

---

## 🎨 Design de la page

### Structure

1. **Header** avec titre et lien retour
2. **Filtres** : Sélection du mois et de l'année
3. **Informations de période** : Affichage de la période comptable (20 → 20)
4. **Liste des dettes** : Cartes par client avec détails
5. **Résumé global** : Totaux HT, TTC et nombre de clients

### Présentation

- **Cartes par client** : Chaque client a sa propre carte
- **Sous-cartes par photocopieur** : Chaque photocopieur est dans une sous-carte
- **Design moderne** : Dégradés, ombres, animations
- **Responsive** : Adapté mobile/tablette/desktop

---

## 🔧 API Endpoint

### Route
```
GET /API/paiements_dettes.php
```

### Paramètres
- `month` (optionnel) : Mois (1-12), défaut = mois courant
- `year` (optionnel) : Année, défaut = année courante

### Réponse JSON

```json
{
  "ok": true,
  "dettes": [
    {
      "client_id": 1,
      "numero_client": "CLI001",
      "raison_sociale": "Entreprise ABC",
      "photocopieurs": [
        {
          "mac_norm": "AABBCCDDEEFF",
          "mac_address": "AA:BB:CC:DD:EE:FF",
          "serial": "SN123456",
          "model": "HP LaserJet",
          "compteur_depart_bw": 1000,
          "compteur_depart_color": 200,
          "compteur_debut_bw": 1000,
          "compteur_debut_color": 200,
          "compteur_fin_bw": 1500,
          "compteur_fin_color": 350,
          "consumption_bw": 500,
          "consumption_color": 150,
          "montant_bw_ht": 25.00,
          "montant_bw_ttc": 30.00,
          "montant_color_ht": 13.50,
          "montant_color_ttc": 16.50,
          "total_ht": 38.50,
          "total_ttc": 46.50
        }
      ],
      "total_ht": 38.50,
      "total_ttc": 46.50
    }
  ],
  "period": {
    "month": 1,
    "year": 2024,
    "date_debut": "2024-01-20",
    "date_fin": "2024-02-20",
    "label": "20/01/2024 → 20/02/2024"
  },
  "tarifs": {
    "bw_ht": 0.05,
    "bw_ttc": 0.06,
    "color_ht": 0.09,
    "color_ttc": 0.11
  }
}
```

---

## 📊 Requêtes SQL utilisées

### 1. Récupération de tous les relevés (pour trouver le premier compteur)

```sql
SELECT 
    mac_norm,
    Timestamp,
    COALESCE(TotalBW, 0) as TotalBW,
    COALESCE(TotalColor, 0) as TotalColor
FROM (
    SELECT mac_norm, Timestamp, TotalBW, TotalColor
    FROM compteur_relevee
    WHERE mac_norm IS NOT NULL AND mac_norm != ''
    UNION ALL
    SELECT mac_norm, Timestamp, TotalBW, TotalColor
    FROM compteur_relevee_ancien
    WHERE mac_norm IS NOT NULL AND mac_norm != ''
) AS combined
ORDER BY mac_norm, Timestamp ASC
```

### 2. Récupération des relevés dans la période (20 → 20)

```sql
SELECT 
    mac_norm,
    Timestamp,
    COALESCE(TotalBW, 0) as TotalBW,
    COALESCE(TotalColor, 0) as TotalColor
FROM (
    SELECT mac_norm, Timestamp, TotalBW, TotalColor
    FROM compteur_relevee
    WHERE mac_norm IS NOT NULL 
      AND mac_norm != ''
      AND Timestamp >= :date_start1 
      AND Timestamp <= :date_end1
    UNION ALL
    SELECT mac_norm, Timestamp, TotalBW, TotalColor
    FROM compteur_relevee_ancien
    WHERE mac_norm IS NOT NULL 
      AND mac_norm != ''
      AND Timestamp >= :date_start2 
      AND Timestamp <= :date_end2
) AS combined
ORDER BY mac_norm, Timestamp ASC
```

### 3. Récupération des clients et leurs photocopieurs

```sql
SELECT 
    c.id as client_id,
    c.numero_client,
    c.raison_sociale,
    pc.mac_norm,
    pc.MacAddress,
    pc.SerialNumber,
    COALESCE(
        (SELECT Model FROM compteur_relevee WHERE mac_norm = pc.mac_norm ORDER BY Timestamp DESC LIMIT 1),
        (SELECT Model FROM compteur_relevee_ancien WHERE mac_norm = pc.mac_norm ORDER BY Timestamp DESC LIMIT 1),
        'Inconnu'
    ) as Model
FROM clients c
INNER JOIN photocopieurs_clients pc ON pc.id_client = c.id
WHERE pc.mac_norm IS NOT NULL AND pc.mac_norm != ''
ORDER BY c.raison_sociale, pc.mac_norm
```

---

## 🎯 Fonctionnalités

### ✅ Calcul automatique
- Calcul des dettes selon les règles de tarification
- Période comptable du 20 au 20
- Gestion des relevés manquants (dernier disponible)

### ✅ Affichage clair
- Cartes par client
- Détails par photocopieur
- Totaux HT et TTC
- Résumé global

### ✅ Filtres
- Sélection du mois
- Sélection de l'année
- Mise à jour automatique

### ✅ Navigation
- Lien depuis la page Paiements
- Lien retour vers Paiements

---

## 📝 Notes importantes

### Gestion des MAC non attribuées
- Seuls les photocopieurs **attribués à un client** sont affichés
- Les photocopieurs sans client ne génèrent pas de dette

### Calcul de la consommation
- La consommation est calculée depuis le **premier compteur enregistré** (compteur de départ)
- Cela permet d'avoir une vision globale de la consommation depuis le début

### Période comptable
- Si aucun relevé le 20, on utilise le dernier relevé disponible avant le 20
- Cela garantit qu'on a toujours une valeur pour calculer la dette

---

## 🚀 Utilisation

1. **Accéder à la page** :
   - Depuis la page Paiements : Cliquer sur "💰 Dettes clients"
   - Ou directement : `/public/paiements_dettes.php`

2. **Sélectionner la période** :
   - Choisir le mois et l'année dans les filtres
   - La page se met à jour automatiquement

3. **Consulter les dettes** :
   - Voir les dettes par client
   - Voir les détails par photocopieur
   - Voir le résumé global

---

## ✅ Résumé

| Fonctionnalité | Statut |
|----------------|--------|
| Calcul automatique des dettes | ✅ |
| Période comptable 20 → 20 | ✅ |
| Tarifs N&B et Couleur | ✅ |
| Affichage par client | ✅ |
| Détails par photocopieur | ✅ |
| Totaux HT/TTC | ✅ |
| Résumé global | ✅ |
| Filtres mois/année | ✅ |
| Design moderne | ✅ |
| Responsive | ✅ |

---

Tout est prêt et fonctionnel ! 🎉

