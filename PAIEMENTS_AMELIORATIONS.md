# 📊 Améliorations de la page Paiements

## 📁 Fichiers modifiés

### 1. **Page Frontend**
- **Fichier** : `public/paiements.php`
- **Modifications** :
  - ✅ Correction des couleurs du graphique (rouge pour couleur, noir pour N&B)
  - ✅ Ajout du bouton "Export Excel"
  - ✅ Filtres automatiques (déjà fonctionnels)

### 2. **API Export Excel**
- **Fichier** : `API/export_paiements_excel.php` (NOUVEAU)
- **Description** : API pour générer un fichier Excel avec les données de consommation

### 3. **Styles CSS**
- **Fichier** : `assets/css/paiements.css`
- **Modifications** :
  - ✅ Couleur rouge pour la courbe couleur
  - ✅ Styles pour le bouton Export Excel
  - ✅ Amélioration du layout du header du graphique

---

## 🎨 Corrections des couleurs

### Graphique Chart.js
- **Noir et blanc** : `rgb(0, 0, 0)` (noir)
- **Couleur** : `rgb(220, 38, 38)` (rouge)

### Légende
- Les couleurs de la légende correspondent aux courbes du graphique

---

## 📤 Export Excel

### Fonctionnalités
- ✅ Bouton "Export Excel" dans l'en-tête du graphique
- ✅ Export des données filtrées selon les critères sélectionnés
- ✅ Format Excel (.xlsx) avec PhpSpreadsheet ou CSV en fallback

### Colonnes du fichier Excel

1. **MAC adresse** - Adresse MAC du photocopieur
2. **Photocopieur** - Nom du client + modèle (ou "Photocopieur non attribué")
3. **Compteur départ N&B** - Premier compteur noir et blanc enregistré
4. **Compteur départ Couleur** - Premier compteur couleur enregistré
5. **Compteur début N&B** - Compteur N&B au début de la période filtrée
6. **Compteur début Couleur** - Compteur couleur au début de la période filtrée
7. **Compteur fin N&B** - Compteur N&B à la fin de la période filtrée
8. **Compteur fin Couleur** - Compteur couleur à la fin de la période filtrée
9. **Consommation N&B** - Calcul : `compteur_fin_N&B - compteur_depart_N&B`
10. **Consommation Couleur** - Calcul : `compteur_fin_Couleur - compteur_depart_Couleur`
11. **Période sélectionnée** - day / month / year
12. **date_start** - Date de début du filtre
13. **date_end** - Date de fin du filtre

### Calcul de la consommation

Pour chaque MAC :
```
Consommation N&B = Compteur fin N&B - Compteur départ N&B
Consommation Couleur = Compteur fin Couleur - Compteur départ Couleur
```

Où :
- **Compteur départ** = Premier compteur enregistré (toutes dates confondues)
- **Compteur début** = Premier compteur dans la période filtrée
- **Compteur fin** = Dernier compteur dans la période filtrée

---

## 🛠 Installation requise (optionnelle)

### PhpSpreadsheet (pour Excel natif)

Si vous voulez générer de vrais fichiers Excel (.xlsx), installez PhpSpreadsheet :

```bash
composer require phpoffice/phpspreadsheet
```

**Note** : Si PhpSpreadsheet n'est pas installé, l'API génère automatiquement un fichier CSV compatible Excel.

---

## 📊 Structure de l'export

### Données exportées

Pour chaque **MAC adresse** dans la période filtrée :

1. **Informations de base** :
   - MAC adresse
   - Nom du photocopieur (client + modèle)
   
2. **Compteurs de départ** (premier relevé global) :
   - N&B
   - Couleur
   
3. **Compteurs dans la période** :
   - Début N&B
   - Début Couleur
   - Fin N&B
   - Fin Couleur
   
4. **Consommation calculée** :
   - N&B = Fin - Départ
   - Couleur = Fin - Départ
   
5. **Métadonnées** :
   - Période sélectionnée
   - Dates de début et fin

---

## 🔧 Filtres automatiques

Les filtres fonctionnent automatiquement sans bouton "Appliquer" :

- ✅ **Période** : Mise à jour automatique lors du changement
- ✅ **Photocopieur** : Mise à jour automatique lors de la sélection
- ✅ **Dates** : Mise à jour automatique lors du changement

---

## 🎯 Utilisation

### Export Excel

1. Sélectionner les filtres souhaités (période, photocopieur, dates)
2. Cliquer sur le bouton **"Export Excel"**
3. Le fichier se télécharge automatiquement avec le nom : `paiements_YYYY-MM-DD_HHMMSS.xlsx` (ou `.csv`)

### Format du fichier

- **Si PhpSpreadsheet est installé** : Fichier Excel (.xlsx) avec formatage
- **Sinon** : Fichier CSV (.csv) avec séparateur `;` et BOM UTF-8 (compatible Excel)

---

## ✅ Points importants

### Gestion des MAC non attribuées
- Les photocopieurs sans client sont affichés comme "Photocopieur non attribué"
- Leur consommation est quand même calculée et exportée

### Calcul de la consommation
- La consommation est calculée depuis le **premier compteur enregistré** (compteur de départ)
- Cela permet d'avoir une vision globale de la consommation depuis le début

### Performance
- Les requêtes utilisent des index sur `mac_norm` et `Timestamp`
- UNION ALL pour combiner efficacement les deux tables
- Filtrage côté serveur pour réduire les données

---

## 📝 Notes techniques

### API Export Excel

- **Route** : `/API/export_paiements_excel.php`
- **Méthode** : GET
- **Paramètres** :
  - `period` : day / month / year
  - `mac` : MAC adresse (optionnel)
  - `date_start` : Date de début (YYYY-MM-DD)
  - `date_end` : Date de fin (YYYY-MM-DD)

### Sécurité

- ✅ Authentification requise
- ✅ Validation des paramètres
- ✅ Protection contre les injections SQL (requêtes préparées)
- ✅ Validation du format MAC

---

## 🚀 Déploiement

Tous les fichiers sont prêts :
- ✅ `public/paiements.php` - Page avec bouton export
- ✅ `API/export_paiements_excel.php` - API d'export
- ✅ `assets/css/paiements.css` - Styles mis à jour

**Optionnel** : Installer PhpSpreadsheet pour les fichiers Excel natifs :
```bash
composer require phpoffice/phpspreadsheet
```

---

## 📦 Résumé des modifications

| Fichier | Type | Description |
|---------|------|-------------|
| `public/paiements.php` | Modification | Couleurs corrigées, bouton export ajouté |
| `API/export_paiements_excel.php` | Nouveau | API d'export Excel/CSV |
| `assets/css/paiements.css` | Modification | Styles pour bouton export, couleurs |

---

## 🎉 Fonctionnalités finales

✅ Graphique en ligne avec couleurs correctes (rouge/noir)  
✅ Filtres automatiques fonctionnels  
✅ Export Excel avec toutes les colonnes demandées  
✅ Calcul correct de la consommation depuis le premier compteur  
✅ Gestion des MAC non attribuées  
✅ Support CSV en fallback si PhpSpreadsheet n'est pas installé  

