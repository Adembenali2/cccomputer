# Validation import `database/full_schema.sql`

## Prérequis

- Docker Desktop **démarré** (ou un MySQL/MariaDB accessible en TCP).
- Node.js 18+.

## 1. Démarrer MySQL de test

```bash
cd tools/schema-validation
docker compose up -d
```

Attendre ~15–30 s que le conteneur soit prêt.

## 2. Importer le schéma et croiser avec le PHP

```bash
npm install
set MYSQL_HOST=127.0.0.1
set MYSQL_PORT=13307
set MYSQL_USER=root
set MYSQL_PASSWORD=testroot
set MYSQL_DATABASE=cc_schema_test
npm run validate
```

Sous Linux/macOS : `export MYSQL_HOST=...` etc.

Le script :

1. `DROP DATABASE` / `CREATE DATABASE` sur `MYSQL_DATABASE`
2. exécute `../../database/full_schema.sql` en une passe (`multipleStatements`)
3. vérifie la présence d’index critiques
4. compare les tables référencées dans le PHP (hors `archive_legacy`) avec la base importée

## 3. Sans base (analyse statique seulement)

```bash
npm run validate:static
```

Utile en CI ou quand MySQL n’est pas disponible ; ne remplace pas un import réel.

## Notes

- Moteur cible : **MySQL 8+** ou **MariaDB 10.4+** (vues avec `ROW_NUMBER()`).
- Le fichier `full_schema.sql` utilise `utf8mb4_general_ci` pour rester compatible MySQL **et** MariaDB.
