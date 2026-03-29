<?php
// src/Controllers/StatsController.php
namespace App\Controllers;

use Auth;
use App\Repositories\StatsRepository;

/**
 * Contrôleur dédié aux Tableaux de Bord (Dashboards) et Statistiques.
 * Utilise StatsRepository pour s'abstraire de la compléxité SQL.
 */
class StatsController
{
    private StatsRepository $stats;

    public function __construct()
    {
        $this->stats = new StatsRepository();
    }

    // =========================================================================
    // GET /stats/overview
    // KPIs globaux : adhérents, séances, paiements, présences (Dashboard Accueil)
    // =========================================================================
    public function overview(): void
    {
        $this->requireCoachOrAdmin();
        $currentYear = date('Y');

        $data = $this->stats->getOverviewStats($currentYear);

        http_response_code(200);
        echo json_encode($data);
    }

    // =========================================================================
    // GET /stats/sessions?months=6
    // Historique des séances avec présences
    // =========================================================================
    public function sessionHistory(): void
    {
        $this->requireCoachOrAdmin();

        $months = min(max((int)($_GET['months'] ?? 6), 1), 24);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        $sessions = $this->stats->getSessionHistory($since);

        http_response_code(200);
        echo json_encode(['sessions' => $sessions, 'since' => $since]);
    }

    // =========================================================================
    // GET /stats/sessions/{sessionId}/detail
    // Détail d'une séance (Infos + Trombinoscope des pointages)
    // =========================================================================
    public function sessionDetail(string $sessionId): void
    {
        $this->requireCoachOrAdmin();

        $detail = $this->stats->getSessionDetail($sessionId);

        if (!$detail) {
            http_response_code(404);
            echo json_encode(['error' => 'Séance introuvable']);
            return;
        }

        // On caste correctement le bit SQL de présence en vrai booléen
        foreach ($detail['attendees'] as &$a) {
            $a['attended'] = (bool) $a['attended'];
        }

        http_response_code(200);
        echo json_encode($detail);
    }

    // =========================================================================
    // GET /stats/members
    // Stats démographiques : âge, genres, groupes, progression des inscriptions
    // =========================================================================
    public function memberStats(): void
    {
        $this->requireCoachOrAdmin();

        $stats = $this->stats->getMemberStats();

        http_response_code(200);
        echo json_encode($stats);
    }

    // =========================================================================
    // GET /stats/payments?months=12
    // Évolution des paiements (mensuelle) et méthodes utilisées
    // =========================================================================
    public function paymentStats(): void
    {
        $this->requireCoachOrAdmin();

        $months = min(max((int)($_GET['months'] ?? 12), 1), 36);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        $stats = $this->stats->getPaymentStats($since);

        http_response_code(200);
        echo json_encode($stats);
    }

    // =========================================================================
    // GET /stats/attendance?months=6
    // Assiduité (Analyse de la fréquentation pure)
    // =========================================================================
    public function attendanceStats(): void
    {
        $this->requireCoachOrAdmin();

        $months = min(max((int)($_GET['months'] ?? 6), 1), 24);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        $stats = $this->stats->getAttendanceStats($since);

        http_response_code(200);
        echo json_encode($stats);
    }

    // =========================================================================
    // GET /stats/logs
    // Liste des derniers journaux d'événements de création/pointage/suppression
    // =========================================================================
    public function getLogs(): void
    {
        $this->requireCoachOrAdmin();

        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $action = trim($_GET['action'] ?? 'attendance_saved');

        $rows = $this->stats->getLogs($action, $limit);

        // Au besoin, on désérialise le JSON stocké en string dans details
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
    // Téléchargement sécurisé d'un fichier de Log unique JSON
    // =========================================================================
    public function downloadLog(string $logId): void
    {
        $this->requireCoachOrAdmin();

        // L'Id de log vient de l'URL (! typé INT en BDD, mais routé en string ici)
        $id = (int)$logId;
        $row = $this->stats->getLogById($id);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Log introuvable']);
            return;
        }

        $details = is_string($row['details']) ? json_decode($row['details'], true) : $row['details'];
        $filename = 'log-' . substr((string)$row['id'], 0, 8) . '-' . date('Ymd', strtotime($row['created_at'])) . '.json';

        // Headers pour forcer un téléchargement "Enregistrer sous..." dans le navigateur
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
    // Génère un export massif Tableur CSV pour faire de la BI côté gérant
    // =========================================================================
    public function exportSessionsCsv(): void
    {
        $this->requireCoachOrAdmin();

        $months = min((int)($_GET['months'] ?? 12), 36);
        $since  = date('Y-m-d', strtotime("-{$months} months"));

        $rows = $this->stats->getSessionsExportData($since);

        // Injection du header pour CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sessions-export-' . date('Ymd') . '.csv"');

        $out = fopen('php://output', 'w');
        // Obligatoire pour Windows / Excel FR (force la lecture UTF8 direct)
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); 
        
        // Entête de colonnes
        fputcsv($out, [
            'Date', 'Heure début', 'Heure fin', 'Séance', 'Type', 'Lieu', 'Coach',
            'Membre', 'Email', 'Présent', 'Heure présence', 'Date inscription'
        ], ';');

        // Parcours du recordset pour l'injecter ligne par ligne dans le flux
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
    // Helpers (Privés)
    // =========================================================================
    
    /**
     * Garde-fou (Guard Clause) limitant l'accès aux profils Administrateurs et Coachs.
     */
    private function requireCoachOrAdmin(): void
    {
        $currentUser = Auth::requireAuth();
        $userRole = $currentUser['role'] ?? 'adherent';
        
        if (!in_array($userRole, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès réservé aux coachs et administrateurs']);
            exit;
        }
    }
}

