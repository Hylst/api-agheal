<?php
// src/Controllers/PaymentController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class PaymentController
{
    /**
     * GET /payments
     * Liste tous les règlements avec noms résolus.
     * Query params optionnels : ?user_id=&method=&received_by=
     */
    public function index(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $db = Database::getInstance();

        $where = [];
        $params = [];

        if (!empty($_GET['user_id'])) {
            $where[] = 'ph.user_id = ?';
            $params[] = $_GET['user_id'];
        }
        if (!empty($_GET['method'])) {
            $where[] = 'ph.payment_method = ?';
            $params[] = $_GET['method'];
        }
        if (!empty($_GET['received_by'])) {
            $where[] = 'ph.received_by = ?';
            $params[] = $_GET['received_by'];
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT
                ph.id,
                ph.user_id,
                ph.amount,
                ph.payment_date,
                ph.payment_method,
                ph.renewal_date,
                ph.received_by,
                ph.comment,
                ph.created_at,
                CONCAT(pa.first_name, ' ', pa.last_name) AS adherent_name,
                pa.email AS adherent_email,
                CONCAT(pc.first_name, ' ', pc.last_name) AS coach_name
            FROM payments_history ph
            LEFT JOIN profiles pa ON ph.user_id = pa.id
            LEFT JOIN profiles pc ON ph.received_by = pc.id
            $whereClause
            ORDER BY ph.created_at DESC
        ";

        $stmt = $db->query($sql, $params);
        $payments = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode(['data' => $payments]);
    }

    /**
     * GET /payments/summary
     * Agrégations pour le tableau de bord.
     */
    public function summary(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $db = Database::getInstance();

        // Total global
        $totalStmt = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments_history");
        $total = $totalStmt->fetch()['total'];

        // Total mois en cours
        $monthStmt = $db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM payments_history
            WHERE YEAR(payment_date) = YEAR(CURRENT_DATE)
              AND MONTH(payment_date) = MONTH(CURRENT_DATE)
        ");
        $monthTotal = $monthStmt->fetch()['total'];

        // Par méthode de paiement
        $methodStmt = $db->query("
            SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
            FROM payments_history
            WHERE payment_method IS NOT NULL
            GROUP BY payment_method
        ");
        $byMethod = $methodStmt->fetchAll();

        // Par coach
        $coachStmt = $db->query("
            SELECT
                ph.received_by,
                CONCAT(p.first_name, ' ', p.last_name) AS coach_name,
                COUNT(*) AS count,
                COALESCE(SUM(ph.amount), 0) AS total
            FROM payments_history ph
            LEFT JOIN profiles p ON ph.received_by = p.id
            WHERE ph.received_by IS NOT NULL
            GROUP BY ph.received_by, p.first_name, p.last_name
        ");
        $byCoach = $coachStmt->fetchAll();

        // Par mois (6 derniers mois)
        $monthlyStmt = $db->query("
            SELECT
                DATE_FORMAT(payment_date, '%Y-%m') AS month,
                COUNT(*) AS count,
                COALESCE(SUM(amount), 0) AS total
            FROM payments_history
            WHERE payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
            ORDER BY month DESC
        ");
        $byMonth = $monthlyStmt->fetchAll();

        // Nombre total de règlements
        $countStmt = $db->query("SELECT COUNT(*) AS count FROM payments_history");
        $count = $countStmt->fetch()['count'];

        http_response_code(200);
        echo json_encode([
            'total' => (float) $total,
            'month_total' => (float) $monthTotal,
            'count' => (int) $count,
            'by_method' => $byMethod,
            'by_coach' => $byCoach,
            'by_month' => $byMonth,
        ]);
    }

    /**
     * POST /payments
     * Enregistre un nouveau règlement.
     */
    public function create(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);

        $required = ['user_id', 'amount', 'payment_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(422);
                echo json_encode(['error' => "Le champ '$field' est requis"]);
                return;
            }
        }

        $db = Database::getInstance();

        $db->query(
            "INSERT INTO payments_history
                (user_id, amount, payment_date, payment_method, renewal_date, received_by, comment)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['user_id'],
                $data['amount'],
                $data['payment_date'],
                $data['payment_method'] ?? null,
                $data['renewal_date'] ?? null,
                $data['received_by'] ?? null,
                $data['comment'] ?? null,
            ]
        );

        http_response_code(201);
        echo json_encode(['message' => 'Règlement enregistré']);
    }

    /**
     * DELETE /payments/{id}
     * Supprime un règlement (Admin uniquement).
     */
    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);

        $db = Database::getInstance();
        $db->query("DELETE FROM payments_history WHERE id = ?", [$id]);

        http_response_code(200);
        echo json_encode(['message' => 'Règlement supprimé']);
    }
}
