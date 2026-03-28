<?php
// src/Controllers/StatsController.php
namespace App\Controllers;

use Database;
use Auth;

class StatsController
{
    /** Répertoire des fichiers de log physiques */
    private const LOG_DIR = __DIR__ . '/../../logs/sessions';

    // =========================================================================
    // GET /stats/overview
    // KPIs globaux : adhérents, séances, paiements, présences
    // =========================================================================
    public function overview(): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        // Total membres (rôle adherent actif)
        $totalMembers = $db->query("
            SELECT COUNT(DISTINCT p.id) as cnt
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
        ")->fetch()['cnt'] ?? 0;

        // Membres actifs (statut_compte = 'actif')
        $activeMembers = $db->query("
            SELECT COUNT(DISTINCT p.id) as cnt
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent' AND p.statut_compte = 'actif'
        ")->fetch()['cnt'] ?? 0;

        // Séances passées (status != draft, date <= today)
        $pastSessions = $db->query("
            SELECT COUNT(*) as cnt FROM sessions
            WHERE status != 'draft' AND date <= CURDATE()
        ")->fetch()['cnt'] ?? 0;

        // Séances à venir
        $upcomingSessions = $db->query("
            SELECT COUNT(*) as cnt FROM sessions
            WHERE status = 'published' AND date > CURDATE()
        ")->fetch()['cnt'] ?? 0;

        // Total présences enregistrées
        $totalAttended = $db->query("
            SELECT COUNT(*) as cnt FROM registrations WHERE attended = 1
        ")->fetch()['cnt'] ?? 0;

        // Taux de présence global (présents / inscrits sur séances passées)
        $regRow = $db->query("
            SELECT COUNT(*) as total_reg,
                   SUM(r.attended) as total_attended
            FROM registrations r
            JOIN sessions s ON s.id = r.session_id
            WHERE s.date <= CURDATE() AND s.status != 'draft'
        ")->fetch();
        $attendanceRate = 0;
        if ($regRow && $regRow['total_reg'] > 0) {
            $attendanceRate = round(($regRow['total_attended'] / $regRow['total_reg']) * 100, 1);
        }

        // Revenus totaux cette année
        $currentYear = date('Y');
        $yearRevenue = $db->query("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM payments_history
            WHERE YEAR(payment_date) = ?
        ", [$currentYear])->fetch()['total'] ?? 0;

        // Paiements en attente
        $pendingPayments = $db->query("
            SELECT COUNT(DISTINCT p.id) as cnt
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent' AND p.payment_status = 'en_attente'
        ")->fetch()['cnt'] ?? 0;

        // Certificats médicaux expirés (> 1 an)
        $expiredCertif = $db->query("
            SELECT COUNT(DISTINCT p.id) as cnt
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
              AND (p.medical_certificate_date IS NULL OR p.medical_certificate_date < DATE_SUB(CURDATE(), INTERVAL 1 YEAR))
        ")->fetch()['cnt'] ?? 0;

        http_response_code(200);
        echo json_encode([
            'members' => [
                'total'   => (int) $totalMembers,
                'active'  => (int) $activeMembers,
                'pending_payment' => (int) $pendingPayments,
                'expired_certif'  => (int) $expiredCertif,
            ],
            'sessions' => [
                'past'     => (int) $pastSessions,
                'upcoming' => (int) $upcomingSessions,
            ],
            'attendance' => [
                'total'   => (int) $totalAttended,
                'rate_pct' => (float) $attendanceRate,
            ],
            'payments' => [
                'year_revenue'    => (float) $yearRevenue,
                'pending_count'   => (int) $pendingPayments,
                'current_year'    => (int) $currentYear,
            ],
        ]);
    }

    // =========================================================================
    // GET /stats/sessions?months=6
    // Historique des séances avec présences
    // =========================================================================
    public function sessionHistory(): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        $months = min(max((int)($_GET['months'] ?? 6), 1), 24);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        $rows = $db->query("
            SELECT
                s.id,
                s.title,
                s.date,
                s.start_time,
                s.end_time,
                s.status,
                st.name AS session_type,
                l.name AS location,
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
        ", [$since])->fetchAll();

        http_response_code(200);
        echo json_encode(['sessions' => $rows, 'since' => $since]);
    }

    // =========================================================================
    // GET /stats/sessions/{sessionId}/detail
    // Détail d'une séance : inscrits + présents
    // =========================================================================
    public function sessionDetail(string $sessionId): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        $session = $db->query("
            SELECT s.*, st.name AS session_type, l.name AS location,
                   CONCAT(p.first_name, ' ', p.last_name) AS coach_name
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l ON l.id = s.location_id
            LEFT JOIN profiles p ON p.id = s.created_by
            WHERE s.id = ?
        ", [$sessionId])->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Séance introuvable']);
            return;
        }

        $attendees = $db->query("
            SELECT r.user_id, r.attended, r.attended_at, r.created_at AS registered_at,
                   m.first_name, m.last_name, m.email
            FROM registrations r
            JOIN profiles m ON m.id = r.user_id
            WHERE r.session_id = ?
            ORDER BY m.last_name ASC
        ", [$sessionId])->fetchAll();

        foreach ($attendees as &$a) {
            $a['attended'] = (bool) $a['attended'];
        }

        http_response_code(200);
        echo json_encode(['session' => $session, 'attendees' => $attendees]);
    }

    // =========================================================================
    // GET /stats/members
    // Stats démographiques : âge, genre (calculé depuis age), groupes
    // =========================================================================
    public function memberStats(): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        // Répartition par tranche d'âge
        $ageRows = $db->query("
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
        ")->fetchAll();

        // Répartition par statut paiement
        $paymentStatusRows = $db->query("
            SELECT p.payment_status, COUNT(*) AS count
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
            GROUP BY p.payment_status
        ")->fetchAll();

        // Répartition par statut compte
        $accountStatusRows = $db->query("
            SELECT p.statut_compte, COUNT(*) AS count
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
            GROUP BY p.statut_compte
        ")->fetchAll();

        // Répartition par groupe
        $groupRows = $db->query("
            SELECT g.name AS group_name, COUNT(ug.user_id) AS member_count
            FROM groups g
            LEFT JOIN user_groups ug ON ug.group_id = g.id
            GROUP BY g.id, g.name
            ORDER BY member_count DESC
        ")->fetchAll();

        // Adhérents sans groupe
        $noGroupCount = $db->query("
            SELECT COUNT(DISTINCT p.id) as cnt
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
              AND p.id NOT IN (SELECT user_id FROM user_groups)
        ")->fetch()['cnt'] ?? 0;

        // Certificats médicaux
        $certifStats = $db->query("
            SELECT
                SUM(CASE WHEN medical_certificate_date IS NULL THEN 1 ELSE 0 END) AS missing,
                SUM(CASE WHEN medical_certificate_date < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN medical_certificate_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS valid
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent'
        ")->fetch();

        // Nouvelles inscriptions par mois (12 derniers mois)
        $newMembersRows = $db->query("
            SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS month, COUNT(*) AS count
            FROM profiles p
            JOIN user_roles ur ON ur.user_id = p.id
            WHERE ur.role = 'adherent' AND p.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY month
            ORDER BY month ASC
        ")->fetchAll();

        http_response_code(200);
        echo json_encode([
            'age_brackets'     => $ageRows,
            'payment_status'   => $paymentStatusRows,
            'account_status'   => $accountStatusRows,
            'groups'           => $groupRows,
            'no_group_count'   => (int) $noGroupCount,
            'certif'           => $certifStats,
            'new_members_trend'=> $newMembersRows,
        ]);
    }

    // =========================================================================
    // GET /stats/payments?months=12
    // Stats paiements : totaux, par méthode, par mois
    // =========================================================================
    public function paymentStats(): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        $months = min(max((int)($_GET['months'] ?? 12), 1), 36);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        // Total et par méthode
        $byMethod = $db->query("
            SELECT payment_method,
                   COUNT(*) AS count,
                   COALESCE(SUM(amount), 0) AS total
            FROM payments_history
            WHERE payment_date >= ?
            GROUP BY payment_method
        ", [$since])->fetchAll();

        // Par mois
        $byMonth = $db->query("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month,
                   COUNT(*) AS count,
                   COALESCE(SUM(amount), 0) AS total
            FROM payments_history
            WHERE payment_date >= ?
            GROUP BY month
            ORDER BY month ASC
        ", [$since])->fetchAll();

        // Total période
        $total = $db->query("
            SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) as count
            FROM payments_history WHERE payment_date >= ?
        ", [$since])->fetch();

        http_response_code(200);
        echo json_encode([
            'by_method' => $byMethod,
            'by_month'  => $byMonth,
            'total'     => (float) ($total['total'] ?? 0),
            'count'     => (int) ($total['count'] ?? 0),
            'since'     => $since,
        ]);
    }

    // =========================================================================
    // GET /stats/attendance?months=6
    // Stats présences par type de séance, par mois
    // =========================================================================
    public function attendanceStats(): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        $months = min(max((int)($_GET['months'] ?? 6), 1), 24);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        // Par type de séance
        $byType = $db->query("
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
        ", [$since])->fetchAll();

        // Par mois
        $byMonth = $db->query("
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
        ", [$since])->fetchAll();

        // Top membres les plus assidus
        $topMembers = $db->query("
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
        ", [$since])->fetchAll();

        http_response_code(200);
        echo json_encode([
            'by_type'     => $byType,
            'by_month'    => $byMonth,
            'top_members' => $topMembers,
            'since'       => $since,
        ]);
    }

    // =========================================================================
    // GET /stats/logs
    // Liste des entrées de log (table logs, action = attendance_saved)
    // =========================================================================
    public function getLogs(): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $action = $_GET['action'] ?? 'attendance_saved';

        $rows = $db->query("
            SELECT l.id, l.user_id, l.action, l.details, l.created_at,
                   CONCAT(p.first_name, ' ', p.last_name) AS author_name
            FROM logs l
            LEFT JOIN profiles p ON p.id = l.user_id
            WHERE l.action = ?
            ORDER BY l.created_at DESC
            LIMIT ?
        ", [$action, $limit])->fetchAll();

        foreach ($rows as &$row) {
            if (is_string($row['details'])) {
                $row['details'] = json_decode($row['details'], true);
            }
        }

        http_response_code(200);
        echo json_encode(['logs' => $rows, 'count' => count($rows)]);
    }

    // =========================================================================
    // GET /stats/logs/{logId}/download
    // Téléchargement d'un log JSON individuel comme fichier
    // =========================================================================
    public function downloadLog(string $logId): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        $row = $db->query("SELECT * FROM logs WHERE id = ?", [$logId])->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Log introuvable']);
            return;
        }

        $details = is_string($row['details']) ? json_decode($row['details'], true) : $row['details'];
        $filename = 'log-' . substr($logId, 0, 8) . '-' . date('Ymd', strtotime($row['created_at'])) . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode([
            'id'         => $row['id'],
            'action'     => $row['action'],
            'created_at' => $row['created_at'],
            'details'    => $details,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // GET /stats/logs/export
    // Export CSV global de toutes les séances avec présences
    // =========================================================================
    public function exportSessionsCsv(): void
    {
        $this->requireCoachOrAdmin();
        $db = Database::getInstance();

        $months = min((int)($_GET['months'] ?? 12), 36);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        $rows = $db->query("
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
        ", [$since])->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sessions-export-' . date('Ymd') . '.csv"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 pour Excel
        fputcsv($out, [
            'Date', 'Heure début', 'Heure fin', 'Séance', 'Type', 'Lieu', 'Coach',
            'Membre', 'Email', 'Présent', 'Heure présence', 'Date inscription'
        ], ';');

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['date'],
                $row['start_time'],
                $row['end_time'],
                $row['title'],
                $row['session_type'],
                $row['location'],
                $row['coach'],
                $row['member_name'],
                $row['member_email'],
                $row['attended'] ? 'Oui' : 'Non',
                $row['attended_at'] ?? '',
                $row['registered_at'] ?? '',
            ], ';');
        }
        fclose($out);
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    private function requireCoachOrAdmin(): void
    {
        $currentUser = Auth::requireAuth();
        // Auth::requireAuth() retourne roles comme un tableau (ex: ['coach', 'adherent'])
        $userRoles = $currentUser['roles'] ?? [];
        if (empty(array_intersect($userRoles, ['coach', 'admin']))) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès réservé aux coachs et administrateurs']);
            exit;
        }
    }
}
