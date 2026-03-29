<?php
// src/Repositories/StatsRepository.php
namespace App\Repositories;

use App\Repositories\BaseRepository;
use PDO;

class StatsRepository extends BaseRepository
{
    /**
     * Récupère la vue d'ensemble (Overview) pour le dashboard administrateur
     */
    public function getOverviewStats(string $currentYear): array
    {
        // Membres (actifs, pending_payment, certif_expire)
        $memberStats = $this->db->query("
            SELECT
                COUNT(DISTINCT p.id) as total,
                SUM(CASE WHEN p.statut_compte = 'actif' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN p.payment_status = 'en_attente' THEN 1 ELSE 0 END) as pending_payment,
                SUM(CASE WHEN (p.medical_certificate_date IS NULL OR p.medical_certificate_date < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) THEN 1 ELSE 0 END) as expired_certif
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
        ")->fetch(PDO::FETCH_ASSOC);

        // Séances
        $sessionStats = $this->db->query("
            SELECT
                SUM(CASE WHEN status != 'draft' AND date <= CURDATE() THEN 1 ELSE 0 END) as past,
                SUM(CASE WHEN status = 'published' AND date > CURDATE() THEN 1 ELSE 0 END) as upcoming
            FROM sessions
        ")->fetch(PDO::FETCH_ASSOC);

        // Présences
        $attendance = $this->db->query("
            SELECT
                COUNT(r.id) as total_registered,
                SUM(CASE WHEN r.attended = 1 THEN 1 ELSE 0 END) as total_attended
            FROM registrations r
            JOIN sessions s ON s.id = r.session_id
            WHERE s.date <= CURDATE() AND s.status != 'draft'
        ")->fetch(PDO::FETCH_ASSOC);

        // Revenus de l'année
        $revenue = $this->db->query("
            SELECT COALESCE(SUM(amount), 0) as year_revenue
            FROM payments_history
            WHERE YEAR(payment_date) = ?
        ", [$currentYear])->fetch(PDO::FETCH_ASSOC);

        return [
            'members'      => [
                'total'           => (int) ($memberStats['total'] ?? 0),
                'active'          => (int) ($memberStats['active'] ?? 0),
                'pending_payment' => (int) ($memberStats['pending_payment'] ?? 0),
                'expired_certif'  => (int) ($memberStats['expired_certif'] ?? 0),
            ],
            'sessions'     => [
                'past'     => (int) ($sessionStats['past'] ?? 0),
                'upcoming' => (int) ($sessionStats['upcoming'] ?? 0),
            ],
            'attendance'   => [
                'total'    => (int) ($attendance['total_attended'] ?? 0),
                'rate_pct' => ($attendance['total_registered'] > 0) ? round(($attendance['total_attended'] / $attendance['total_registered']) * 100, 1) : 0,
            ],
            'payments'     => [
                'year_revenue'  => (float) ($revenue['year_revenue'] ?? 0),
                'pending_count' => (int) ($memberStats['pending_payment'] ?? 0),
                'current_year'  => (int) $currentYear,
            ]
        ];
    }

    /**
     * Historique des séances (avec statistiques de présence) depuis X mois
     */
    public function getSessionHistory(string $sinceDate): array
    {
        return $this->fetchAll("
            SELECT
                s.id, s.title, s.date, s.start_time, s.end_time, s.status,
                st.name AS session_type, l.name AS location,
                CONCAT(p.first_name, ' ', p.last_name) AS coach_name,
                (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id) AS count_registered,
                (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id AND r.attended = 1) AS count_attended
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l ON l.id = s.location_id
            LEFT JOIN profiles p ON p.id = s.created_by
            WHERE s.date >= ? AND s.status != 'draft'
            ORDER BY s.date DESC, s.start_time DESC
            LIMIT 200
        ", [$sinceDate]);
    }

    /**
     * Détails complets d'une séance (Infos + liste des participants)
     */
    public function getSessionDetail(string $sessionId): ?array
    {
        $session = $this->db->query("
            SELECT s.*, st.name AS session_type, l.name AS location,
                   CONCAT(p.first_name, ' ', p.last_name) AS coach_name
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l ON l.id = s.location_id
            LEFT JOIN profiles p ON p.id = s.created_by
            WHERE s.id = ?
        ", [$sessionId])->fetch(PDO::FETCH_ASSOC);

        if (!$session) return null;

        $attendees = $this->fetchAll("
            SELECT r.user_id, r.attended, r.attended_at, r.created_at AS registered_at,
                   m.first_name, m.last_name, m.email
            FROM registrations r
            JOIN profiles m ON m.id = r.user_id
            WHERE r.session_id = ?
            ORDER BY m.last_name ASC
        ", [$sessionId]);

        return ['session' => $session, 'attendees' => $attendees];
    }

    /**
     * Statistiques démographiques de la base adhérente
     */
    public function getMemberStats(): array
    {
        $ageBrackets = $this->fetchAll("
            SELECT
                CASE
                    WHEN p.age IS NULL THEN 'Non renseigné'
                    WHEN p.age < 18    THEN '< 18 ans'
                    WHEN p.age < 25    THEN '18-24 ans'
                    WHEN p.age < 35    THEN '25-34 ans'
                    WHEN p.age < 45    THEN '35-44 ans'
                    WHEN p.age < 55    THEN '45-54 ans'
                    WHEN p.age < 65    THEN '55-64 ans'
                    ELSE '65+ ans'
                END AS age_bracket,
                COUNT(*) AS count
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
            GROUP BY age_bracket
            ORDER BY MIN(COALESCE(p.age, 999))
        ");

        $paymentStatus = $this->fetchAll("
            SELECT p.payment_status, COUNT(*) AS count
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
            GROUP BY p.payment_status
        ");

        $accountStatus = $this->fetchAll("
            SELECT p.statut_compte, COUNT(*) AS count
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
            GROUP BY p.statut_compte
        ");

        $groups = $this->fetchAll("
            SELECT g.name AS group_name, COUNT(ug.user_id) AS member_count
            FROM groups g
            LEFT JOIN user_groups ug ON ug.group_id = g.id
            GROUP BY g.id, g.name
            ORDER BY member_count DESC
        ");

        $noGroupCount = $this->db->query("
            SELECT COUNT(DISTINCT p.id) as cnt
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
              AND p.id NOT IN (SELECT user_id FROM user_groups)
        ")->fetchColumn();

        $certifStats = $this->db->query("
            SELECT
                SUM(CASE WHEN medical_certificate_date IS NULL THEN 1 ELSE 0 END) AS missing,
                SUM(CASE WHEN medical_certificate_date < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN medical_certificate_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS valid
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
        ")->fetch(PDO::FETCH_ASSOC);

        $newMembers = $this->fetchAll("
            SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS month, COUNT(*) AS count
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent' AND p.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY month
            ORDER BY month ASC
        ");

        return [
            'age_brackets'     => $ageBrackets,
            'payment_status'   => $paymentStatus,
            'account_status'   => $accountStatus,
            'groups'           => $groups,
            'no_group_count'   => (int) $noGroupCount,
            'certif'           => $certifStats,
            'new_members_trend'=> $newMembers,
        ];
    }

    /**
     * Statistiques globales des paiements (évolution, méthodes) sur une période donnée
     */
    public function getPaymentStats(string $sinceDate): array
    {
        $byMethod = $this->fetchAll("
            SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
            FROM payments_history
            WHERE payment_date >= ?
            GROUP BY payment_method
        ", [$sinceDate]);

        $byMonth = $this->fetchAll("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
            FROM payments_history
            WHERE payment_date >= ?
            GROUP BY month
            ORDER BY month ASC
        ", [$sinceDate]);

        $totalRow = $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) as count
            FROM payments_history WHERE payment_date >= ?
        ", [$sinceDate])->fetch(PDO::FETCH_ASSOC);

        return [
            'by_method' => $byMethod,
            'by_month'  => $byMonth,
            'total'     => (float) ($totalRow['total'] ?? 0),
            'count'     => (int) ($totalRow['count'] ?? 0),
            'since'     => $sinceDate,
        ];
    }

    /**
     * Statistiques d'assiduité (Taux de présence, absents, fréquentation par type...)
     */
    public function getAttendanceStats(string $sinceDate): array
    {
        $byType = $this->fetchAll("
            SELECT
                COALESCE(st.name, 'Non défini') AS session_type,
                COUNT(DISTINCT s.id) AS session_count,
                COUNT(r.id) AS total_registered,
                SUM(r.attended) AS total_attended,
                ROUND(AVG(CASE WHEN r.attended = 1 THEN 1 ELSE 0 END) * 100, 1) AS attendance_rate
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN registrations r ON r.session_id = s.id
            WHERE s.date >= ? AND s.status != 'draft'
            GROUP BY st.id, st.name
            ORDER BY total_attended DESC
        ", [$sinceDate]);

        $byMonth = $this->fetchAll("
            SELECT
                DATE_FORMAT(s.date, '%Y-%m') AS month,
                COUNT(DISTINCT s.id) AS session_count,
                COUNT(r.id) AS total_registered,
                SUM(r.attended) AS total_attended
            FROM sessions s
            LEFT JOIN registrations r ON r.session_id = s.id
            WHERE s.date >= ? AND s.status != 'draft'
            GROUP BY month
            ORDER BY month ASC
        ", [$sinceDate]);

        $topMembers = $this->fetchAll("
            SELECT
                m.id,
                CONCAT(m.first_name, ' ', m.last_name) AS name,
                COUNT(*) AS attended_count
            FROM registrations r
            JOIN profiles m ON m.id = r.user_id
            JOIN sessions s ON s.id = r.session_id
            WHERE r.attended = 1 AND s.date >= ?
            GROUP BY m.id, m.first_name, m.last_name
            ORDER BY attended_count DESC
            LIMIT 10
        ", [$sinceDate]);

        return [
            'by_type'     => $byType,
            'by_month'    => $byMonth,
            'top_members' => $topMembers,
            'since'       => $sinceDate,
        ];
    }

    /**
     * Récupère la liste des derniers logs techniques en BDD
     */
    public function getLogs(string $action, int $limit): array
    {
        return $this->fetchAll("
            SELECT l.id, l.user_id, l.action, l.details, l.created_at,
                   CONCAT(p.first_name, ' ', p.last_name) AS author_name
            FROM logs l
            LEFT JOIN profiles p ON p.id = l.user_id
            WHERE l.action = ?
            ORDER BY l.created_at DESC
            LIMIT ?
        ", [$action, $limit]);
    }

    /**
     * Récupère un log technique individuel
     */
    public function getLogById(int $logId): ?array
    {
        $log = $this->db->query("SELECT * FROM logs WHERE id = ?", [$logId])->fetch(PDO::FETCH_ASSOC);
        return $log ?: null;
    }

    /**
     * Exporte l'historique massif et complet de toutes les présences (pour CSV Excel)
     */
    public function getSessionsExportData(string $sinceDate): array
    {
        return $this->fetchAll("
            SELECT
                s.date, s.start_time, s.end_time, s.title,
                COALESCE(st.name, '') AS session_type,
                COALESCE(l.name, '') AS location,
                COALESCE(CONCAT(coach.first_name, ' ', coach.last_name), '') AS coach,
                COALESCE(CONCAT(m.first_name, ' ', m.last_name), '') AS member_name,
                m.email AS member_email,
                r.attended,
                r.attended_at,
                r.created_at AS registered_at
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l ON l.id = s.location_id
            LEFT JOIN profiles coach ON coach.id = s.created_by
            LEFT JOIN registrations r ON r.session_id = s.id
            LEFT JOIN profiles m ON m.id = r.user_id
            WHERE s.date >= ? AND s.status != 'draft'
            ORDER BY s.date DESC, s.start_time DESC, m.last_name ASC
        ", [$sinceDate]);
    }
}
