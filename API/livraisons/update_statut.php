<?php
declare(strict_types=1);

/**
 * POST JSON — Mise à jour rapide du statut d'une livraison (tableau / planning).
 * Même logique métier que livraison.php (update_delivery) : date_reelle, stock si livrée, historique.
 */
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/historique.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$csrf = (string) ($data['csrf_token'] ?? '');
if ($csrf === '') {
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}
requireCsrfToken($csrf !== '' ? $csrf : null);

$id = isset($data['id']) ? (int) $data['id'] : 0;
$newStatut = isset($data['statut']) ? (string) $data['statut'] : '';

$allowed = ['planifiee', 'en_cours', 'livree', 'annulee'];
if ($id <= 0 || !in_array($newStatut, $allowed, true)) {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

$today = date('Y-m-d');

try {
    $pdo = getPdoOrFail();
    $stmt = $pdo->prepare(
        'SELECT l.id, l.id_client, l.id_livreur, l.reference, l.adresse_livraison,
                l.objet, l.date_prevue, l.date_reelle, l.statut, l.commentaire,
                l.product_type, l.product_id, l.product_qty,
                l.created_at, l.updated_at
         FROM livraisons l
         WHERE l.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $liv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$liv) {
        jsonResponse(['success' => false, 'error' => 'Livraison introuvable'], 404);
    }

    $role = (string) ($_SESSION['emploi'] ?? '');
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $isAdminOrDirigeant = in_array($role, ['Admin', 'Dirigeant'], true);
    $livreurId = (int) ($liv['id_livreur'] ?? 0);
    $isOwnLivreur = $role === 'Livreur' && $livreurId > 0 && $livreurId === $uid;

    if (!$isAdminOrDirigeant && !$isOwnLivreur) {
        jsonResponse(['success' => false, 'error' => 'Non autorisé'], 403);
    }

    $oldStatut = (string) ($liv['statut'] ?? '');
    $dateReelle = $liv['date_reelle'] ?? null;
    $isBecomingLivree = ($newStatut === 'livree' && $oldStatut !== 'livree');
    $isLeavingLivree = ($oldStatut === 'livree' && $newStatut !== 'livree');

    if ($newStatut === 'livree' && empty($dateReelle)) {
        $dateReelle = $today;
    } elseif ($newStatut !== 'livree') {
        $dateReelle = null;
    }

    $datePrevue = $liv['date_prevue'] ?? null;

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            'UPDATE livraisons
             SET statut = :statut,
                 date_prevue = :date_prevue,
                 date_reelle = :date_reelle,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $upd->execute([
            ':statut' => $newStatut,
            ':date_prevue' => $datePrevue,
            ':date_reelle' => $dateReelle,
            ':id' => $id,
        ]);

        if ($isBecomingLivree) {
            $productType = $liv['product_type'] ?? null;
            $productId = isset($liv['product_id']) ? (int) $liv['product_id'] : 0;
            $productQty = isset($liv['product_qty']) ? (int) $liv['product_qty'] : 0;
            $clientId = (int) ($liv['id_client'] ?? 0);

            if (!empty($productType) && $productId > 0 && $productQty > 0 && $clientId > 0) {
                $stmtQte = $pdo->prepare('SELECT quantite FROM stock WHERE id = ? FOR UPDATE');
                $stmtQte->execute([$productId]);
                $qteAvant = (int) ($stmtQte->fetchColumn() ?: 0);
                $qteApres = $qteAvant - $productQty;
                if ($qteApres < 0) {
                    throw new RuntimeException('Stock insuffisant pour valider la livraison.');
                }

                $stmtMv = $pdo->prepare(
                    'INSERT INTO stock_mouvements
                        (stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc, created_by)
                     VALUES (?, \'livraison\', ?, ?, ?, ?, ?, ?)'
                );
                $stmtMv->execute([
                    $productId,
                    $productQty,
                    $qteAvant,
                    $qteApres,
                    'Livraison client',
                    (string) ($liv['reference'] ?? ''),
                    (int) ($_SESSION['user_id'] ?? 0),
                ]);

                $stmtUpd = $pdo->prepare('UPDATE stock SET quantite = quantite - ? WHERE id = ? AND quantite >= ?');
                $stmtUpd->execute([$productQty, $productId, $productQty]);
                if ($stmtUpd->rowCount() === 0) {
                    throw new RuntimeException('Impossible de mettre à jour le stock (quantité insuffisante).');
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'Stock')) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
        throw $e;
    }

    if ($newStatut === 'planifiee' && $oldStatut !== 'planifiee') {
        $notifyLivreurId = (int) ($liv['id_livreur'] ?? 0);
        if ($notifyLivreurId > 0) {
            try {
                require_once __DIR__ . '/../../includes/NotificationService.php';
                $datePrevLabel = !empty($datePrevue) ? (string) $datePrevue : '';
                NotificationService::create(
                    $notifyLivreurId,
                    'livraison_planifiee',
                    'Livraison planifiée',
                    $datePrevLabel !== ''
                        ? sprintf(
                            'La livraison #%d (%s) est planifiée (date prévue : %s).',
                            $id,
                            $liv['reference'] ?? 'N/A',
                            $datePrevLabel
                        )
                        : sprintf(
                            'La livraison #%d (%s) est planifiée.',
                            $id,
                            $liv['reference'] ?? 'N/A'
                        ),
                    $id,
                    'livraison'
                );
            } catch (Throwable $e) {
                error_log('update_statut.php notification: ' . $e->getMessage());
            }
        }
    }

    try {
        $statutLabels = [
            'planifiee' => 'Planifiée',
            'en_cours' => 'En cours',
            'livree' => 'Livrée',
            'annulee' => 'Annulée',
        ];
        $oldStatutLabel = $statutLabels[$oldStatut] ?? $oldStatut;
        $newStatutLabel = $statutLabels[$newStatut] ?? $newStatut;
        $actionType = $isBecomingLivree ? 'livraison_effectuee' : 'modification';
        $actionLabel = $isBecomingLivree
            ? 'Livraison effectuee — ' . (string) ($liv['reference'] ?? ('#' . $id))
            : 'Livraison mise a jour — ' . (string) ($liv['reference'] ?? ('#' . $id));
        logAction(
            $pdo,
            'livraison',
            $actionType,
            $actionLabel,
            'Nouveau statut: ' . $newStatutLabel,
            $id,
            'livraison.php'
        );
    } catch (Throwable $e) {
        error_log('update_statut.php historique: ' . $e->getMessage());
    }

    jsonResponse([
        'success' => true,
        'date_reelle' => $dateReelle,
        'statut' => $newStatut,
    ]);
} catch (Throwable $e) {
    error_log('update_statut.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
