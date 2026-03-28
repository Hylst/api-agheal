<?php
// src/Repositories/PaymentRepository.php
namespace App\Repositories;

/**
 * Accès aux données de la table payments_history.
 */
class PaymentRepository extends BaseRepository
{
    /**
     * Retourne les règlements filtrés par critères facultatifs.
     * @param array $filters Clés: user_id, method, received_by
     */
    public function findAll(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[]  = 'ph.user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['method'])) {
            $where[]  = 'ph.payment_method = ?';
            $params[] = $filters['method'];
        }
        if (!empty($filters['received_by'])) {
            $where[]  = 'ph.received_by = ?';
            $params[] = $filters['received_by'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return $this->fetchAll("
            SELECT
                ph.id, ph.user_id, ph.amount, ph.payment_date,
                ph.payment_method, ph.renewal_date, ph.received_by,
                ph.comment, ph.created_at,
                CONCAT(pa.first_name, ' ', pa.last_name) AS adherent_name,
                pa.email AS adherent_email,
                CONCAT(pc.first_name, ' ', pc.last_name) AS coach_name
            FROM payments_history ph
            LEFT JOIN profiles pa ON ph.user_id   = pa.id
            LEFT JOIN profiles pc ON ph.received_by = pc.id
            $whereClause
            ORDER BY ph.created_at DESC
        ", $params);
    }

    /** Crée un règlement. */
    public function create(string $userId, float $amount, string $paymentDate,
                           ?string $method, ?string $renewalDate,
                           ?string $receivedBy, ?string $comment): void
    {
        $this->execute(
            "INSERT INTO payments_history
                (user_id, amount, payment_date, payment_method, renewal_date, received_by, comment)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$userId, $amount, $paymentDate, $method, $renewalDate, $receivedBy, $comment]
        );
    }

    /** Supprime un règlement par ID. */
    public function delete(string $id): void
    {
        $this->execute("DELETE FROM payments_history WHERE id = ?", [$id]);
    }

    // ─── Agrégations pour le dashboard ──────────────────────────────────────

    public function totalAmount(): float
    {
        $row = $this->fetchOne("SELECT COALESCE(SUM(amount),0) AS t FROM payments_history");
        return (float)($row['t'] ?? 0);
    }

    public function monthAmount(): float
    {
        $row = $this->fetchOne("
            SELECT COALESCE(SUM(amount),0) AS t FROM payments_history
            WHERE YEAR(payment_date)=YEAR(CURRENT_DATE)
              AND MONTH(payment_date)=MONTH(CURRENT_DATE)
        ");
        return (float)($row['t'] ?? 0);
    }

    public function count(): int
    {
        $row = $this->fetchOne("SELECT COUNT(*) AS c FROM payments_history");
        return (int)($row['c'] ?? 0);
    }

    public function byMethod(): array
    {
        return $this->fetchAll("
            SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
            FROM payments_history WHERE payment_method IS NOT NULL
            GROUP BY payment_method
        ");
    }

    public function byCoach(): array
    {
        return $this->fetchAll("
            SELECT ph.received_by,
                   CONCAT(p.first_name,' ',p.last_name) AS coach_name,
                   COUNT(*) AS count,
                   COALESCE(SUM(ph.amount),0) AS total
            FROM payments_history ph
            LEFT JOIN profiles p ON ph.received_by = p.id
            WHERE ph.received_by IS NOT NULL
            GROUP BY ph.received_by, p.first_name, p.last_name
        ");
    }

    public function byMonth(): array
    {
        return $this->fetchAll("
            SELECT DATE_FORMAT(payment_date,'%Y-%m') AS month,
                   COUNT(*) AS count,
                   COALESCE(SUM(amount),0) AS total
            FROM payments_history
            WHERE payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(payment_date,'%Y-%m')
            ORDER BY month DESC
        ");
    }
}
