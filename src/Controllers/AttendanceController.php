<?php
// src/Controllers/AttendanceController.php
namespace App\Controllers;

use Auth;
use App\Repositories\AttendanceRepository;

/**
 * Contrôleur en charge de la gestion des présences (Pointage des séances).
 * Il ne gère pas la base de données directement : il utilise AttendanceRepository (Single Responsibility).
 */
class AttendanceController
{
    /** Répertoire local de stockage des logs de la séance en JSON */
    private const LOG_DIR = __DIR__ . '/../../logs/sessions';

    private AttendanceRepository $attendance;

    public function __construct()
    {
        // Instanciation du repository qui s'occupe de la communication avec MySQL
        $this->attendance = new AttendanceRepository();
    }

    // =========================================================================
    // GET /sessions/{sessionId}/attendance
    // =========================================================================
    /**
     * Retourne la liste des inscrits + statut de présence pour une séance.
     * Inclut les infos complètes de la séance (et de son coach créateur).
     * Accès sécurisé : limité au profil "coach" ou "admin".
     */
    public function getAttendance(string $sessionId): void
    {
        // 1. Règle de sécurité (Seuls Coachs et Admins peuvent voir le pointage)
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        // 2. On utilise le Repository pour récupérer les infos de la séance
        $session = $this->attendance->getSessionDetails($sessionId);

        // Si la séance n'existe pas, ou erreur d'ID -> 404 Not Found
        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Séance introuvable']);
            return;
        }

        // 3. On demande au Repository la liste complète des inscrits et leur présence
        $rows = $this->attendance->getSessionAttendees($sessionId);

        // 4. On prépare la donnée pour qu'elle soit facilement utilisable en React/Frontend
        $attendees = array_map(fn($row) => [
            'registration_id' => $row['registration_id'],
            'user_id'         => $row['user_id'],
            'first_name'      => $row['first_name'],
            'last_name'       => $row['last_name'],
            'email'           => $row['email'],
            'attended'        => (bool) $row['attended'],
            'attended_at'     => $row['attended_at'],
            'registered_at'   => $row['registered_at'],
            'is_walk_in'      => false,
        ], $rows);

        // 5. On renvoie le tout avec un code 200 (Succès)
        http_response_code(200);
        echo json_encode([
            'session'          => $session,
            'attendees'        => $attendees,
            'count_registered' => count($attendees),
            'count_attended'   => count(array_filter($attendees, fn($a) => $a['attended'])),
        ]);
    }

    // =========================================================================
    // PUT /sessions/{sessionId}/attendance
    // =========================================================================
    /**
     * Met à jour le statut de présence en lot (Batch).
     * S'il y a un ajout de dernière minute (Walk-in), la DB l'enregistre à la volée.
     * Génère des journaux d'événements (logs) de sécurité.
     */
    public function updateAttendance(string $sessionId): void
    {
        // 1. Vérifie si tu as le droit de pointer
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';
        $coachId = $currentUser['sub'];

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        // 2. Réceptionne le tableau des pointages `{ "attendances": [...] }`
        $data = json_decode(file_get_contents('php://input'), true);
        $attendances = $data['attendances'] ?? [];

        if (empty($attendances) || !is_array($attendances)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le champ "attendances" est requis et doit être un tableau']);
            return;
        }

        // 3. Vérifie que la séance existe
        $session = $this->attendance->getSessionDetails($sessionId);

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Séance introuvable']);
            return;
        }

        // 4. Exécute le pointage en base de données et calcule le nombre de mises à jour
        try {
            $stats = $this->attendance->processBatchAttendance($sessionId, $attendances);
        } catch (\Exception $dbError) {
            http_response_code(500);
            echo json_encode(['error' => 'Une erreur est survenue lors du pointage']);
            return;
        }

        // 5. ── ENREGISTREMENT DU LOG (BDD + FICHIER JSON LOCAL) ──────────────────

        // C'est vital de connaître l'état de la séance APRÈS le pointage pour les archives de logs
        $finalStateList = $this->attendance->getSessionAttendees($sessionId);
        
        $attendedList = array_filter($finalStateList, fn($r) => (bool)$r['attended']);
        $registeredList = $finalStateList;
        $now = date('Y-m-d H:i:s');

        // Préparation d'une "Photo/Snapshot" de qui était présent et des conditions
        $logDetails = [
            'session_id'     => $sessionId,
            'session_title'  => $session['title'],
            'session_date'   => $session['date'],
            'session_time'   => $session['start_time'] . ' - ' . $session['end_time'],
            'session_type'   => $session['type_name'],
            'location'       => $session['location_name'],
            'coach_name'     => $session['coach_name'],
            'coach_id'       => $session['created_by'] ?? null,
            'pointed_by_id'  => $coachId,
            'pointed_at'     => $now,
            'count_registered' => count($registeredList),
            'count_attended'   => count($attendedList),
            'registered'     => array_values(array_map(fn($r) => [
                'user_id'      => $r['user_id'],
                'name'         => $r['first_name'] . ' ' . $r['last_name'],
                'email'        => $r['email'],
            ], $registeredList)),
            'attended'       => array_values(array_map(fn($r) => [
                'user_id'      => $r['user_id'],
                'name'         => $r['first_name'] . ' ' . $r['last_name'],
                'email'        => $r['email'],
                'attended_at'  => $r['attended_at'],
            ], $attendedList)),
            'walk_ins_added' => $stats['inserted'], // Les adhérents surprise
        ];

        // Écriture du Log de traçabilité en base de donnée et dans un fichier Backup Json
        $this->attendance->saveLogToDatabase($coachId, $logDetails);
        $this->writeFileLog($session['date'], $sessionId, $logDetails);

        // 6. Confirme la réussite au Frontend
        http_response_code(200);
        echo json_encode([
            'message'        => 'Présences mises à jour',
            'updated'        => $stats['updated'],
            'walk_ins_added' => $stats['inserted'],
            'count_attended' => count($attendedList),
        ]);
    }

    // =========================================================================
    // GET /sessions/{sessionId}/attendance/candidates
    // =========================================================================
    /**
     * Recherche de membres non-inscrits à la séance (pour Walk-in).
     * Utile si le coach a besoin de rajouter quelqu'un au bout du pinceau (via une barre de recherche).
     */
    public function getCandidates(string $sessionId): void
    {
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        // On récupère la requête tapée par l'utilisateur
        $search = trim($_GET['q'] ?? '');

        // On demande au Repository de trouver ces personnes
        $candidates = $this->attendance->getCandidates($sessionId, $search);

        http_response_code(200);
        echo json_encode(['candidates' => $candidates]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Écrit un fichier JSON de sauvegarde de secours (log) dans le disque dur du serveur
     * Format : logs/sessions/YYYY-MM/session-YYYY-MM-DD-{ID}.json
     */
    private function writeFileLog(string $sessionDate, string $sessionId, array $details): void
    {
        try {
            $month  = substr($sessionDate, 0, 7); // "YYYY-MM"
            $dir    = self::LOG_DIR . '/' . $month;

            // Création automatique du dossier s'il n'existe pas
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Un fichier par séance, s'il existe déjà il est écrasé (mis à jour) proprement
            $filename = $dir . '/session-' . $sessionDate . '-' . substr($sessionId, 0, 8) . '.json';
            $content  = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($filename, $content);
        } catch (\Throwable $e) {
            error_log('[AttendanceController] Erreur écriture fichier log temp : ' . $e->getMessage());
        }
    }
}

