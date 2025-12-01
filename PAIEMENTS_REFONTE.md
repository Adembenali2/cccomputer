# 📊 Refonte complète de la page Paiements

## 📁 Fichiers modifiés

### 1. **API - Backend**
- **Fichier** : `API/paiements_data.php`
- **Description** : API refaite avec nouvelle logique de calcul de consommation depuis le premier compteur

### 2. **Page Frontend**
- **Fichier** : `public/paiements.php`
- **Description** : Page refaite avec graphique en ligne, filtres automatiques, design modernisé

### 3. **Styles CSS**
- **Fichier** : `assets/css/paiements.css`
- **Description** : CSS modernisé avec animations, thème sombre, responsive

---

## 🔧 Nouvelle logique de calcul

### Principe
Pour chaque adresse MAC, on calcule la **consommation réelle** depuis le premier compteur enregistré :

```
consommation = compteur_actuel - compteur_depart
```

Où `compteur_depart` est le **premier relevé chronologique** trouvé dans l'une des deux tables :
- `compteur_relevee`
- `compteur_relevee_ancien`

### Avantages
- ✅ Consommation cumulée depuis le début
- ✅ Pas de problème avec les réinitialisations de compteurs
- ✅ Vision globale de l'évolution

---

## 📊 Requêtes SQL utilisées

### 1. Récupération de tous les relevés (pour trouver le premier compteur)

```sql
SELECT 
    mac_norm,
    Timestamp,
    COALESCE(TotalBW, 0) as TotalBW,
    COALESCE(TotalColor, 0) as TotalColor,
    Model,
    MacAddress
FROM (
    SELECT 
        mac_norm,
        Timestamp,
        TotalBW,
        TotalColor,
        Model,
        MacAddress
    FROM compteur_relevee
    WHERE mac_norm IS NOT NULL 
      AND mac_norm != ''
      [AND mac_norm = :mac_norm1]  -- Si filtre MAC
    
    UNION ALL
    
    SELECT 
        mac_norm,
        Timestamp,
        TotalBW,
        TotalColor,
        Model,
        MacAddress
    FROM compteur_relevee_ancien
    WHERE mac_norm IS NOT NULL 
      AND mac_norm != ''
      [AND mac_norm = :mac_norm2]  -- Si filtre MAC
) AS combined
ORDER BY mac_norm, Timestamp ASC
```

**Usage** : Récupère tous les relevés triés par MAC puis par date. Le premier relevé de chaque MAC devient le compteur de départ.

---

### 2. Récupération des relevés filtrés (période sélectionnée)

```sql
SELECT 
    mac_norm,
    Timestamp,
    COALESCE(TotalBW, 0) as TotalBW,
    COALESCE(TotalColor, 0) as TotalColor,
    Model,
    MacAddress
FROM (
    SELECT 
        mac_norm,
        Timestamp,
        TotalBW,
        TotalColor,
        Model,
        MacAddress
    FROM compteur_relevee
    WHERE mac_norm IS NOT NULL 
      AND mac_norm != ''
      AND Timestamp >= :date_start1 
      AND Timestamp <= :date_end1
      [AND mac_norm = :mac_norm1]  -- Si filtre MAC
    
    UNION ALL
    
    SELECT 
        mac_norm,
        Timestamp,
        TotalBW,
        TotalColor,
        Model,
        MacAddress
    FROM compteur_relevee_ancien
    WHERE mac_norm IS NOT NULL 
      AND mac_norm != ''
      AND Timestamp >= :date_start2 
      AND Timestamp <= :date_end2
      [AND mac_norm = :mac_norm2]  -- Si filtre MAC
) AS combined
ORDER BY mac_norm, Timestamp ASC
```

**Usage** : Récupère uniquement les relevés dans la période sélectionnée par l'utilisateur.

---

### 3. Liste des photocopieurs (pour le filtre)

```sql
SELECT DISTINCT
    COALESCE(pc.mac_norm, r.mac_norm) as mac_norm,
    COALESCE(pc.MacAddress, r.MacAddress) as MacAddress,
    COALESCE(pc.SerialNumber, r.SerialNumber) as SerialNumber,
    COALESCE(r.Model, 'Inconnu') as Model,
    COALESCE(c.raison_sociale, 'Photocopieur non attribué') as client_name,
    pc.id_client
FROM (
    SELECT DISTINCT mac_norm, MacAddress, SerialNumber, Model
    FROM compteur_relevee
    WHERE mac_norm IS NOT NULL AND mac_norm != ''
    UNION
    SELECT DISTINCT mac_norm, MacAddress, SerialNumber, Model
    FROM compteur_relevee_ancien
    WHERE mac_norm IS NOT NULL AND mac_norm != ''
) AS r
LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = r.mac_norm
LEFT JOIN clients c ON c.id = pc.id_client
ORDER BY client_name, Model, MacAddress
```

**Usage** : Récupère la liste de tous les photocopieurs (avec ou sans client associé) pour le filtre déroulant.

---

## 🎨 Nouvelles fonctionnalités UI/UX

### 1. Graphique en ligne (Line Chart)
- ✅ Deux courbes : Noir & Blanc et Couleur
- ✅ Courbes lissées (tension: 0.4)
- ✅ Points interactifs avec hover
- ✅ Légende personnalisée en haut du graphique

### 2. Filtres automatiques
- ✅ **Suppression des boutons** "Appliquer" et "Réinitialiser"
- ✅ Mise à jour **automatique** lors des changements :
  - Changement de période → mise à jour automatique
  - Changement de photocopieur → mise à jour automatique
  - Changement de dates → mise à jour automatique
- ✅ Design moderne avec bordures animées au focus

### 3. Design modernisé
- ✅ Animations au chargement (fadeIn)
- ✅ Effets hover sur les cartes
- ✅ Meilleure hiérarchie visuelle
- ✅ Support complet du thème sombre
- ✅ Responsive design amélioré

---

## 🔄 Flux de traitement des données

```
1. Récupération de TOUS les relevés (toutes dates)
   ↓
2. Identification du premier compteur par MAC (compteur_depart)
   ↓
3. Récupération des relevés dans la période filtrée
   ↓
4. Calcul : consommation = compteur_actuel - compteur_depart
   ↓
5. Agrégation par période (jour/mois/année)
   ↓
6. Retour JSON avec labels, données BW, données Couleur
```

---

## 📦 Structure de la réponse JSON

```json
{
  "ok": true,
  "data": {
    "labels": ["2024-01", "2024-02", ...],
    "bw": [1000, 1500, 2000, ...],
    "color": [200, 300, 400, ...],
    "total_bw": 2000,
    "total_color": 400
  },
  "photocopieurs": [
    {
      "mac_norm": "AABBCCDDEEFF",
      "mac_address": "AA:BB:CC:DD:EE:FF",
      "serial": "SN123456",
      "model": "HP LaserJet",
      "client_name": "Entreprise ABC",
      "label": "Entreprise ABC - HP LaserJet (AA:BB:CC:DD:EE:FF)"
    }
  ],
  "filters": {
    "period": "month",
    "mac": "",
    "date_start": "2024-01-01",
    "date_end": "2024-12-31"
  }
}
```

---

## 🎯 Points importants

### Gestion des MAC non attribuées
- Les photocopieurs sans client associé sont affichés comme "Photocopieur non attribué"
- Leur consommation est quand même calculée et affichée

### Gestion des périodes
- **Par jour** : Agrégation quotidienne (30 derniers jours par défaut)
- **Par mois** : Agrégation mensuelle (12 derniers mois par défaut)
- **Par année** : Agrégation annuelle (5 dernières années par défaut)

### Performance
- Les requêtes utilisent des index sur `mac_norm` et `Timestamp`
- UNION ALL pour combiner les deux tables efficacement
- Filtrage côté serveur pour réduire les données transférées

---

## ✅ Tests recommandés

1. **Test avec toute la flotte** : Vérifier que tous les photocopieurs sont pris en compte
2. **Test avec un photocopieur spécifique** : Vérifier le filtre MAC
3. **Test avec différentes périodes** : Jour, mois, année
4. **Test avec MAC non attribuée** : Vérifier l'affichage "Photocopieur non attribué"
5. **Test responsive** : Vérifier sur mobile/tablette

---

## 🚀 Déploiement

Tous les fichiers sont prêts à être déployés :
- ✅ `API/paiements_data.php` - Backend corrigé
- ✅ `public/paiements.php` - Frontend modernisé
- ✅ `assets/css/paiements.css` - Styles modernisés

Aucune migration de base de données nécessaire, les tables existantes sont utilisées.

