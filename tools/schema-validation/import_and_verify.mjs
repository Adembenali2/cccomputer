/**
 * Import ../database/full_schema.sql then verify tables/views/indexes vs PHP codebase.
 *
 * Usage (after Docker compose or any MySQL 8+ / MariaDB 10.4+):
 *   cd tools/schema-validation
 *   npm install
 *   set MYSQL_HOST=127.0.0.1
 *   set MYSQL_PORT=13307
 *   set MYSQL_USER=root
 *   set MYSQL_PASSWORD=testroot
 *   set MYSQL_DATABASE=cc_schema_test
 *   npm run validate
 *
 * Linux/macOS: export MYSQL_HOST=...
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import mysql from 'mysql2/promise';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '..', '..');
const SCHEMA_FILE = path.join(REPO_ROOT, 'database', 'full_schema.sql');

const SQL_KEYWORDS = new Set([
  'select', 'where', 'join', 'left', 'right', 'inner', 'outer', 'on', 'and', 'or', 'not',
  'null', 'as', 'from', 'into', 'update', 'delete', 'set', 'values', 'order', 'by', 'group',
  'having', 'limit', 'offset', 'case', 'when', 'then', 'else', 'end', 'between', 'like',
  'in', 'exists', 'distinct', 'union', 'all', 'with', 'recursive', 'true', 'false',
  'dual', 'interval', 'date', 'time', 'timestamp', 'current_timestamp',
  'information_schema', 'performance_schema', 'mysql', 'sys',
  'error', 'result', 'row', 'rows', 'data', 'tmp', 'temp', 'dual',
  'thead', 'tbody', 'tr', 'td', 'th', 'div', 'span', 'border', 'style', 'class', 'code',
  'dans', 'pour', 'avec', 'de', 'des', 'du', 'si', 'non', 'qui', 'peut', 'cr', 'n', 'may',
  'if', 'else', 'elseif', 'existe', 'existence', 'existante', 'manquante', 'notifications',
  'lectures', 'valeur', 'nom', 'statut', 'id_client', 'db', 'raw', 'deltas', 'dynamique',
  'failed', 'cascade', 'check', 'address_hash', 'last_per_day', 'last_per_month',
  'one_per_day', 'one_per_month', 'unassigned_with_read', 'unassigned_without_read',
  'with_prev', 'v_last', 'v_compteur_unified', 'supprime_destinataire', 'supprime_expediteur',
]);

const config = {
  host: process.env.MYSQL_HOST || '127.0.0.1',
  port: Number(process.env.MYSQL_PORT || 3306),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || '',
  database: process.env.MYSQL_DATABASE || 'cc_schema_test',
  multipleStatements: true,
};

function walkPhpFiles(dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const name of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, name.name);
    if (name.isDirectory()) {
      if (
        name.name === 'node_modules' ||
        name.name === 'vendor' ||
        name.name === 'archive_legacy' ||
        name.name === 'database' ||
        name.name === 'sql'
      ) {
        continue;
      }
      walkPhpFiles(full, out);
    } else if (name.isFile() && name.name.endsWith('.php')) {
      out.push(full);
    }
  }
  return out;
}

function extractSqlIdentifiersFromPhp(content) {
  const tables = new Set();
  const columns = new Set();
  const views = new Set();

  const tablePattern = /\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\b/gi;
  let m;
  while ((m = tablePattern.exec(content)) !== null) {
    const t = m[1].toLowerCase();
    if (!SQL_KEYWORDS.has(t)) tables.add(t);
  }

  const backtickPair = /`([a-zA-Z_][a-zA-Z0-9_]*)`\.`([a-zA-Z_][a-zA-Z0-9_]*)`/g;
  while ((m = backtickPair.exec(content)) !== null) {
    const tbl = m[1].toLowerCase();
    const col = m[2].toLowerCase();
    if (!SQL_KEYWORDS.has(tbl)) {
      tables.add(tbl);
      columns.add(`${tbl}.${col}`);
    }
  }

  return { tables, columns };
}

function parseSchemaTablesAndColumns(sql) {
  const tables = new Map();
  const views = new Map();

  const createTableRe = /CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\(([\s\S]*?)\)\s*ENGINE/gi;
  let cm;
  while ((cm = createTableRe.exec(sql)) !== null) {
    const name = cm[1].toLowerCase();
    const body = cm[2];
    const cols = new Set();
    const lines = body.split(/\n/);
    for (const line of lines) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('PRIMARY') || trimmed.startsWith('KEY') || trimmed.startsWith('UNIQUE') || trimmed.startsWith('CONSTRAINT') || trimmed.startsWith('FOREIGN')) continue;
      const colMatch = /^`([^`]+)`/.exec(trimmed);
      if (colMatch) cols.add(colMatch[1].toLowerCase());
    }
    tables.set(name, cols);
  }

  const createViewRe = /CREATE\s+VIEW\s+`([^`]+)`\s+AS\s+([\s\S]*?);/gi;
  while ((cm = createViewRe.exec(sql)) !== null) {
    views.set(cm[1].toLowerCase(), true);
  }

  return { tables, views };
}

const CRITICAL_INDEXES = [
  { table: 'private_messages', name: 'idx_private_messages_receiver_lu' },
  { table: 'historique', name: 'idx_historique_action' },
  { table: 'compteur_relevee_ancien', name: 'uniq_mac_ts' },
];

async function main() {
  const staticOnly = process.argv.includes('--static-only');

  console.log('Schema file:', SCHEMA_FILE);
  if (!fs.existsSync(SCHEMA_FILE)) {
    console.error('FATAL: full_schema.sql not found');
    process.exit(1);
  }

  const sql = fs.readFileSync(SCHEMA_FILE, 'utf8');

  if (staticOnly) {
    await runStaticReport(sql);
    process.exit(0);
  }

  console.log('Connecting', { host: config.host, port: config.port, user: config.user, database: config.database });
  let conn;
  try {
    conn = await mysql.createConnection({ ...config, database: undefined });
  } catch (e) {
    console.error('FATAL: cannot connect — start MySQL/MariaDB or Docker Compose (tools/schema-validation/docker-compose.yml)');
    console.error(e.message);
    process.exit(1);
  }

  try {
    await conn.query(`DROP DATABASE IF EXISTS \`${config.database}\``);
    await conn.query(`CREATE DATABASE \`${config.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci`);
    await conn.query(`USE \`${config.database}\``);
    console.log('Importing schema (this may take a minute)...');
    await conn.query(sql);
    console.log('Import OK.');
  } catch (e) {
    console.error('IMPORT FAILED:', e.message);
    process.exit(1);
  }

  const [dbRows] = await conn.query(
    'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
    [config.database]
  );
  const dbTables = new Set();
  const dbViews = new Set();
  for (const r of dbRows) {
    const n = r.TABLE_NAME.toLowerCase();
    if (r.TABLE_TYPE === 'VIEW') dbViews.add(n);
    else dbTables.add(n);
  }

  const colRows = await conn.query(
    'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
    [config.database]
  );
  const dbCols = new Map();
  for (const r of colRows[0]) {
    const t = r.TABLE_NAME.toLowerCase();
    if (!dbCols.has(t)) dbCols.set(t, new Set());
    dbCols.get(t).add(r.COLUMN_NAME.toLowerCase());
  }

  for (const { table, name } of CRITICAL_INDEXES) {
    const [idx] = await conn.query(
      'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
      [config.database, table, name]
    );
    if (idx.length === 0) {
      console.warn(`WARN: critical index missing: ${table}.${name}`);
    } else {
      console.log(`OK index: ${table}.${name}`);
    }
  }

  await conn.end();

  const phpFiles = walkPhpFiles(REPO_ROOT);
  const aggTables = new Set();
  const aggColumns = new Set();
  for (const f of phpFiles) {
    const c = fs.readFileSync(f, 'utf8');
    const { tables, columns } = extractSqlIdentifiersFromPhp(c);
    tables.forEach((t) => aggTables.add(t));
    columns.forEach((x) => aggColumns.add(x));
  }

  const schemaParsed = parseSchemaTablesAndColumns(sql);
  const schemaTableNames = new Set(schemaParsed.tables.keys());
  const schemaViewNames = new Set(schemaParsed.views.keys());

  const missingTables = [...aggTables].filter(
    (t) => !dbTables.has(t) && !dbViews.has(t) && !SQL_KEYWORDS.has(t)
  );

  const missingColumns = [];
  for (const ref of aggColumns) {
    const [tbl, col] = ref.split('.');
    if (!tbl || !col || SQL_KEYWORDS.has(tbl)) continue;
    if (!dbTables.has(tbl) && !dbViews.has(tbl)) continue;
    const set = dbCols.get(tbl);
    if (!set || !set.has(col)) {
      missingColumns.push(ref);
    }
  }

  const unusedInCode = [...dbTables].filter((t) => !aggTables.has(t));

  console.log('\n--- Report: tables referenced in PHP but not in imported DB ---');
  console.log(missingTables.length ? missingTables.sort().join('\n') : '(none)');

  console.log('\n--- Report: table.column referenced in PHP but column missing on imported DB (heuristic) ---');
  const uniqMiss = [...new Set(missingColumns)].sort();
  console.log(uniqMiss.length ? uniqMiss.slice(0, 200).join('\n') + (uniqMiss.length > 200 ? `\n... +${uniqMiss.length - 200} more` : '') : '(none or not applicable)');

  console.log('\n--- Report: tables present in DB but never matched by PHP heuristic ---');
  console.log(unusedInCode.sort().join('\n'));

  console.log('\n--- Schema file defines views ---');
  console.log([...schemaViewNames].sort().join('\n'));

  process.exit(0);
}

async function runStaticReport(sql) {
  const phpFiles = walkPhpFiles(REPO_ROOT);
  const aggTables = new Set();
  const aggColumns = new Set();
  for (const f of phpFiles) {
    const c = fs.readFileSync(f, 'utf8');
    const { tables, columns } = extractSqlIdentifiersFromPhp(c);
    tables.forEach((t) => aggTables.add(t));
    columns.forEach((x) => aggColumns.add(x));
  }

  const schemaParsed = parseSchemaTablesAndColumns(sql);
  const schemaTableNames = new Set(schemaParsed.tables.keys());
  const schemaViewNames = new Set(schemaParsed.views.keys());
  const allSchemaObjects = new Set([...schemaTableNames, ...schemaViewNames]);

  const missingInSchema = [...aggTables].filter(
    (t) => !allSchemaObjects.has(t) && !SQL_KEYWORDS.has(t)
  );

  const unusedInPhp = [...schemaTableNames].filter((t) => !aggTables.has(t));

  console.log('--- Static: PHP table/view identifiers not found in full_schema.sql (heuristic) ---');
  console.log(missingInSchema.length ? missingInSchema.sort().join('\n') : '(none)');

  console.log('\n--- Static: CREATE TABLE in schema but not referenced by PHP heuristic ---');
  console.log(unusedInPhp.sort().join('\n'));

  console.log('\n--- Static: `table`.`column` pairs in PHP missing from parsed schema ---');
  const missingCols = [];
  for (const ref of aggColumns) {
    const [tbl, col] = ref.split('.');
    if (!tbl || !col) continue;
    const cols = schemaParsed.tables.get(tbl);
    if (!cols) continue;
    if (!cols.has(col)) missingCols.push(ref);
  }
  const u = [...new Set(missingCols)].sort();
  console.log(u.length ? u.join('\n') : '(none)');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
