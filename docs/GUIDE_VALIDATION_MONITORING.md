# Guide de Validation et Monitoring

Ce guide explique comment utiliser les scripts de validation, d'analyse SQL et de monitoring créés lors de l'audit.

---

## 📋 Scripts Disponibles

### 1. `scripts/validate_corrections.php`
**Objectif** : Valider que toutes les corrections critiques fonctionnent correctement.

**Utilisation** :
```bash
php scripts/validate_corrections.php
```

**Tests effectués** :
- ✅ Variable `$user_id` correctement initialisée dans `dashboard.php`
- ✅ Requêtes SQL préparées pour GET_LOCK/RELEASE_LOCK
- ✅ Requête SELECT 1 avec `prepare()` dans `api_helpers.php`
- ✅ Connexion PDO via `getPdo()`
- ✅ Vérification de l'existence des fichiers modifiés
- ✅ Vérification que les corrections sont présentes dans le code

**Sortie** :
- Liste des tests réussis (✓)
- Avertissements (⚠)
- Erreurs (✗)
- Code de sortie : 0 si succès, 1 si erreurs

---

### 2. `scripts/analyze_sql_performance.php`
**Objectif** : Analyser les performances SQL et identifier les problèmes potentiels.

**Utilisation** :
```bash
php scripts/analyze_sql_performance.php
```

**Analyses effectuées** :
- ✅ Vérification des index sur les colonnes fréquemment utilisées
- ✅ Identification des requêtes avec IN() dynamiques
- ✅ Détection des requêtes complexes (CTE, sous-requêtes)
- ✅ Vérification des requêtes sans LIMIT
- ✅ Vérification de l'utilisation du cache

**Sortie** :
- Liste des index manquants
- Requêtes potentiellement problématiques
- Recommandations d'optimisation

**Recommandations générées** :
1. Exécuter EXPLAIN sur les requêtes complexes
2. Ajouter des index sur les colonnes utilisées dans WHERE et ORDER BY
3. Utiliser le cache pour les requêtes fréquentes
4. Monitorer les requêtes lentes avec le slow query log MySQL
5. Considérer la pagination pour les grandes listes

---

### 3. `scripts/monitor_corrections.php`
**Objectif** : Monitorer les corrections en production et générer un rapport.

**Utilisation** :
```bash
php scripts/monitor_corrections.php
```

**Vérifications effectuées** :
- ✅ Santé de la base de données (connexion, tables critiques)
- ✅ Vérification que les corrections sont actives dans le code
- ✅ Analyse des erreurs récentes (imports, etc.)

**Fichiers générés** :
- `logs/monitoring_YYYY-MM-DD.log` : Log détaillé avec timestamps
- `logs/monitoring_report_YYYY-MM-DD.json` : Rapport JSON structuré

**Format du rapport JSON** :
```json
{
  "timestamp": "2024-01-15 10:30:00",
  "overall_status": "ok|warning|error",
  "database_health": {
    "status": "ok",
    "checks": {
      "connection": {"status": "ok", "message": "..."},
      "tables": {"status": "ok", "message": "..."}
    }
  },
  "corrections_active": {
    "status": "ok",
    "checks": {
      "public/dashboard.php": {"status": "ok", "message": "..."}
    }
  },
  "recent_errors": {
    "status": "ok",
    "checks": {
      "import_errors": {"status": "ok", "message": "..."}
    }
  }
}
```

**Code de sortie** :
- 0 : Tout est OK
- 1 : Erreurs ou avertissements détectés

---

## 🔄 Automatisation

### Cron Job pour le Monitoring

Pour automatiser le monitoring quotidien, ajoutez dans votre crontab :

```bash
# Monitoring quotidien à 2h du matin
0 2 * * * cd /path/to/cccomputer && php scripts/monitor_corrections.php >> logs/cron_monitoring.log 2>&1
```

### Validation après Déploiement

Ajoutez la validation dans votre processus de déploiement :

```bash
# Après chaque déploiement
php scripts/validate_corrections.php
if [ $? -ne 0 ]; then
    echo "ERREUR : Les validations ont échoué"
    exit 1
fi
```

---

## 📊 Interprétation des Résultats

### Script de Validation

**Tous les tests passent (✓)** :
- ✅ Les corrections sont actives et fonctionnelles
- ✅ Le code est prêt pour la production

**Avertissements (⚠)** :
- Variables non initialisées mais avec valeurs par défaut (normal en CLI)
- Verrous non acquis (peut être normal si déjà verrouillé)

**Erreurs (✗)** :
- ❌ Corrections non détectées dans le code
- ❌ Fichiers manquants
- ❌ Erreurs de connexion à la base de données

### Script d'Analyse SQL

**Index manquants** :
- Priorité HAUTE : Colonnes utilisées dans WHERE avec beaucoup de données
- Priorité MOYENNE : Colonnes utilisées dans ORDER BY
- Priorité BASSE : Colonnes utilisées occasionnellement

**Requêtes complexes** :
- Vérifier avec `EXPLAIN` pour identifier les goulots d'étranglement
- Considérer la matérialisation des vues si nécessaire

### Script de Monitoring

**Statut OK** :
- ✅ Toutes les vérifications sont passées
- ✅ Aucune erreur récente détectée

**Statut WARNING** :
- ⚠ Certaines corrections ne sont pas détectées
- ⚠ Erreurs récentes mais non critiques
- ⚠ Tables manquantes (peut être normal selon la configuration)

**Statut ERROR** :
- ❌ Problèmes critiques détectés
- ❌ Connexion à la base de données échouée
- ❌ Fichiers de corrections manquants

---

## 🛠️ Dépannage

### Erreur : "Fichier introuvable"
**Solution** : Vérifier que vous exécutez le script depuis la racine du projet ou ajustez les chemins relatifs.

### Erreur : "Connexion PDO échouée"
**Solution** : Vérifier la configuration de la base de données dans `includes/db_connection.php` ou les variables d'environnement.

### Erreur : "Correction non détectée"
**Solution** : Vérifier que les fichiers modifiés contiennent bien les corrections. Relire le rapport d'audit pour les détails.

### Avertissement : "Variable $user_id = 0"
**Solution** : Normal si exécuté en CLI (pas de session utilisateur). En production web, cette variable sera correctement initialisée.

---

## 📝 Checklist de Validation

Avant de mettre en production, exécuter :

- [ ] `php scripts/validate_corrections.php` - Tous les tests doivent passer
- [ ] `php scripts/analyze_sql_performance.php` - Vérifier les recommandations
- [ ] `php scripts/monitor_corrections.php` - Statut global doit être "ok"

Après mise en production :

- [ ] Vérifier les logs de monitoring quotidiennement
- [ ] Exécuter l'analyse SQL mensuellement
- [ ] Réexécuter la validation après chaque mise à jour majeure

---

## 🔗 Références

- `docs/RAPPORT_AUDIT_COMPLET.md` - Rapport complet de l'audit
- `CLEANUP_LOG.md` - Journal des nettoyages précédents
- `docs/DIAGNOSTIC_IMPORT_NON_TRAITE.md` - Diagnostic des imports

---

**Fin du guide**

