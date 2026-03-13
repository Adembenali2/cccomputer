<?php
declare(strict_types=1);
/**
 * API pour modifier une programmation d'envoi
 */

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

try {
    $pdo = getPdo();
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !is_array($data) || empty($data['id'])) {
        jsonResponse(['ok' => false, 'error' => 'ID requis'], 400);
    }

    $id = (int)$data['id'];
    if ($id <= 0) {
        jsonResponse(['ok' => false, 'error' => 'ID invalide'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, statut FROM factures_envois_programmes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonResponse(['ok' => false, 'error' => 'Programmation introuvable'], 404);
    }

    if ($row['statut'] !== 'en_attente') {
        jsonResponse(['ok' => false, 'error' => 'Seules les programmations en attente peuvent être modifiées'], 400);
    }

    $dateEnvoi = $data['date_envoi_programmee'] ?? null;
    if ($dateEnvoi) {
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $dateEnvoi);
        if (!$dt) {
            $dt = \DateTime::createFromFormat('Y-m-d H:i', $dateEnvoi);
        }
        if (!$dt) {
            $dt = \DateTime::createFromFormat('Y-m-d', $dateEnvoi);
            if ($dt) {
                $dt->setTime(9, 0);
            }
        }
        if (!$dt || $dt < new \DateTime()) {
            jsonResponse(['ok' => false, 'error' => 'Date/heure invalide ou passée'], 400);
        }
    }

    $updates = [];
    $params = [':id' => $id];

    $fields = ['sujet', 'message', 'email_destination', 'date_envoi_programmee'];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            if ($f === 'date_envoi_programmee' && $dateEnvoi) {
                $updates[] = "{$f} = :{$f}";
                $params[":{$f}"] = $dt->format('Y-m-d H:i:s');
            } elseif ($f !== 'date_envoi_programmee') {
                $updates[] = "{$f} = :{$f}";
                $params[":{$f}"] = trim($data[$f] ?? '');
            }
        }
    }

    if (empty($updates)) {
        jsonResponse(['ok' => true, 'message' => 'Aucune modification']);
    }

    $sql = "UPDATE factures_envois_programmes SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['ok' => true, 'message' => 'Programmation mise à jour']);
} catch (PDOException $e) {
    error_log('[factures_programmation_update] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
} catch (Throwable $e) {
    error_log('[factures_programmation_update] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
