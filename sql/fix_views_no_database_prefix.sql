-- Correction des vues pour supprimer le préfixe 'railway.'
-- Les vues doivent être créées dans la base de données active, sans préfixe

-- Vue v_compteur_last (corrigée - sans préfixe railway.)
DROP VIEW IF EXISTS `v_compteur_last`;
CREATE VIEW `v_compteur_last` AS
WITH t AS (
    SELECT 
        r.id, r.Timestamp, r.IpAddress, r.Nom, r.Model, r.SerialNumber, 
        r.MacAddress, r.Status, r.TonerBlack, r.TonerCyan, r.TonerMagenta, 
        r.TonerYellow, r.TotalPages, r.FaxPages, r.CopiedPages, r.PrintedPages, 
        r.BWCopies, r.ColorCopies, r.MonoCopies, r.BichromeCopies, r.BWPrinted, 
        r.BichromePrinted, r.MonoPrinted, r.ColorPrinted, r.TotalColor, 
        r.TotalBW, r.DateInsertion, r.mac_norm,
        ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.Timestamp DESC) AS rn
    FROM compteur_relevee r
)
SELECT * FROM t WHERE t.rn = 1;

-- Vue v_lcd_stock (corrigée - sans préfixe railway.)
DROP VIEW IF EXISTS `v_lcd_stock`;
CREATE VIEW `v_lcd_stock` AS
SELECT 
    l.id AS lcd_id,
    l.marque,
    l.reference,
    l.etat,
    l.modele,
    l.taille,
    l.resolution,
    l.connectique,
    l.prix,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM lcd_catalog l
LEFT JOIN lcd_moves m ON m.lcd_id = l.id
GROUP BY l.id, l.marque, l.reference, l.etat, l.modele, l.taille, 
         l.resolution, l.connectique, l.prix;

-- Vue v_paper_stock (corrigée - sans préfixe railway.)
DROP VIEW IF EXISTS `v_paper_stock`;
CREATE VIEW `v_paper_stock` AS
SELECT 
    c.id AS paper_id,
    c.marque,
    c.modele,
    c.poids,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM paper_catalog c
LEFT JOIN paper_moves m ON m.paper_id = c.id
GROUP BY c.id, c.marque, c.modele, c.poids;

-- Note: Les vues v_toner_stock et v_pc_stock sont créées dans create_missing_views.sql
-- La vue v_photocopieurs_clients_last semble incomplète dans le schéma, elle n'est pas utilisée par le code PHP

