-- Importer les clients existants
INSERT INTO historique (type, action, label, detail, ref_id, ref_url, created_at)
SELECT
  'client',
  'creation',
  CONCAT('Nouveau client — ', COALESCE(raison_sociale, nom)),
  CONCAT(COALESCE(ville, ''), IF(telephone IS NOT NULL, CONCAT(' | ', telephone), '')),
  id,
  CONCAT('clients.php?id=', id),
  COALESCE(created_at, NOW())
FROM clients
WHERE COALESCE(statut, 'actif') = 'actif'
ON DUPLICATE KEY UPDATE id = id;

-- Importer les factures existantes
INSERT INTO historique (type, action, label, detail, ref_id, ref_url, created_at)
SELECT
  'facture',
  'creation',
  CONCAT(COALESCE(numero_facture, numero, id), ' — ',
         COALESCE((SELECT raison_sociale FROM clients WHERE id = factures.id_client LIMIT 1), 'Client inconnu')),
  CONCAT('Montant: ', FORMAT(COALESCE(montant_ttc, 0), 2), ' € | Statut: ', COALESCE(statut, '—')),
  id,
  CONCAT('factures.php?id=', id),
  COALESCE(created_at, NOW())
FROM factures
ON DUPLICATE KEY UPDATE id = id;

-- Importer les SAV existants
INSERT INTO historique (type, action, label, detail, ref_id, ref_url, created_at)
SELECT
  'sav',
  'creation',
  CONCAT('SAV — ', COALESCE((SELECT raison_sociale FROM clients WHERE id = sav.id_client LIMIT 1), 'Client inconnu')),
  COALESCE(description, ''),
  id,
  CONCAT('sav.php?id=', id),
  COALESCE(created_at, NOW())
FROM sav
ON DUPLICATE KEY UPDATE id = id;
