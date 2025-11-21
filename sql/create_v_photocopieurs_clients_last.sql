-- Vue v_photocopieurs_clients_last (corrigée - sans préfixe railway.)
-- Cette vue joint photocopieurs_clients avec les derniers relevés de compteur
DROP VIEW IF EXISTS `v_photocopieurs_clients_last`;
CREATE VIEW `v_photocopieurs_clients_last` AS
WITH v_compteur_last AS (
    SELECT 
        r.*,
        ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
    FROM compteur_relevee r
    WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
),
v_last AS (
    SELECT * FROM v_compteur_last WHERE rn = 1
)
SELECT 
    COALESCE(pc.mac_norm, v.mac_norm) AS mac_norm,
    pc.id_client AS client_id,
    pc.SerialNumber,
    pc.MacAddress,
    v.Model,
    v.Nom,
    v.`Timestamp` AS last_ts,
    v.TonerBlack,
    v.TonerCyan,
    v.TonerMagenta,
    v.TonerYellow,
    v.TotalBW,
    v.TotalColor,
    v.TotalPages,
    v.Status,
    v.IpAddress
FROM photocopieurs_clients pc
LEFT JOIN v_last v ON v.mac_norm = pc.mac_norm
WHERE pc.id_client IS NOT NULL;

