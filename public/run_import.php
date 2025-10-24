<?php
// PROTÉGE CETTE PAGE si possible (clé secrète simple) :
if (!isset($_GET['key']) || $_GET['key'] !== getenv('ADMIN_IMPORT_KEY')) {
  http_response_code(403);
  exit('Forbidden');
}

require_once __DIR__ . '/../API/scripts/import_ionos_compteurs.php';
// ou si ton repo a "api/" en minuscules, adapte le chemin : ../api/scripts/...
