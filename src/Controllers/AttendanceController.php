<?php
// src/Controllers/AttendanceController.php
//
// Pointage des presences. Utilise par le coach pendant la seance (souvent
// telephone a la main) pour cocher les presents, ajouter un walk-in, et
// sauvegarder l'appel a la fin.
//
// Specificite : la sauvegarde finale ecrit le log a 2 endroits :
//   1) ligne dans table logs (BDD, requetable)
//   2) fichier JSON sur disque (logs/sessions/YYYY-MM/)
// Choix volontaire : redondance simple pour fiabilite audit (litige adherent,
// ou si la BDD se corrompt on a encore le fichier).

namespace App\Controllers;

use Auth;
use App\Repositories\AttendanceRepository;

class AttendanceController
{
    /** Dossier racine des logs JSON sur disque. */
    private const LOG_DIR = __DIR__ . '/../../logs/sessions';

    private AttendanceRepository $attendance;

    public function __construct()
    {
        // Toute la logique SQL est dans le Repo (pattern habituel, cf STRUCTURE.md).
        $this->attendance = new AttendanceRepository();
    }

    // =========================================================================
    // GET /sessions/{sessionId}/attendance
    // =========================================================================
    /**
     * Liste inscrits + statut presence d'une seance. Au chargement de l'ecran.
     * Reserve coach/admin (un adherent n'a pas a voir qui est inscrit).
     */
    public function getAttendance(string $sessionId): void
    {
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Acces refuse']);
            return;
        }

        $session = $this->attendance->getSessionDetails($sessionId);

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Seance introuvable']);
            return;
        }

        $rows = $this->attendance->getSessionAttendees($sessionId);

        // attended est un TINYINT(1) en BDD, on le passe en bool pour le front.
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
     * Sauvegarde finale du pointage. 3 etapes :
     *   1) UPDATE batch sur registrations (presents + walk-ins)
     *   2) INSERT ligne d'audit dans logs
     *   3) Ecriture fichier JSON miroir sur disque (cf entete fichier)
     */
    public function updateAttendance(string $sessionId): void
    {
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';
        $coachId = $currentUser['sub'];

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Acces refuse']);
            return;
        }

        // Payload attendu : { attendances: [{registration_id, attended}, ...] }
        // walk-ins = registration_id null + user_id present.
        $data = json_decode(file_get_contents('php://input'), true);
        $attendances = $data['attendances'] ?? [];

        if (empty($attendances) || !is_array($attendances)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le champ "attendances" est requis et doit etre un tableau']);
            return;
        }

        $session = $this->attendance->getSessionDetails($sessionId);

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Seance introuvable']);
            return;
        }

        try {
            $stats = $this->attendance->processBatchAttendance($sessionId, $attendances);
        } catch (\Exception $dbError) {
            // On ne fuite pas l'erreur SQL au client. Detail dans error_log serveur.
            http_response_code(500);
            echo json_encode(['error' => 'Une erreur est survenue lors du pointage']);
            return;
        }

        // === DOUBLE-LOG : on snapshot l'etat FINAL apres pointage ============
        // On relit depuis la BDD plutot que de se baser sur le payload client
        // (qui peut etre incomplet).
        $finalStateList = $this->attendance->getSessionAttendees($sessionId);

        $attendedList   = array_filter($finalStateList, fn($r) => (bool)$r['attended']);
        $registeredList = $finalStateList;
        $now = date('Y-m-d H:i:s');

        // Snapshot complet : seance + qui etait inscrit / present + qui a pointe.
        $logDetails = [
            'session_id'     => $sessionId,
            'session_title'  => $session['title'],
            'session_date'   => $session['date'],
            'session_time'   => $session['start_time'] . ' - ' . $session['end_time'],
            'session_type'   => $session['type_name'],
            'location'       => $session['location_name'],
            'coach_name'     => $session['coach_name'],
            'coach_id'       => $session['created_by'] ?? null,
            'pointed_by_id'  => $coachId, // peut differer du coach createur
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
            'walk_ins_added' => $stats['inserted'],
        ];

        // Copie 1 : BDD (table logs, auditable depuis Stats)
        $this->attendance->saveLogToDatabase($coachId, $logDetails);

        // Copie 2 : fichier disque (resilience hors-BDD).
        // Si KO, on log en interne mais on ne casse pas la reponse user :
        // la BDD est deja a jour.
        $this->writeFileLog($session['date'], $sessionId, $logDetails);

        http_response_code(200);
        echo json_encode([
            'message'        => 'Presences mises a jour',
            'updated'        => $stats['updated'],
            'walk_ins_added' => $stats['inserted'],
            'count_attended' => count($attendedList),
        ]);
    }

    // =========================================================================
    // GET /sessions/{sessionId}/attendance/candidates
    // =========================================================================
    /**
     * Recherche adherents non encore inscrits, pour la barre walk-in.
     */
    public function getCandidates(string $sessionId): void
    {
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Acces refuse']);
            return;
        }

        $search = trim($_GET['q'] ?? '');
        $candidates = $this->attendance->getCandidates($sessionId, $search);

        http_response_code(200);
        echo json_encode(['candidates' => $candidates]);
    }

    // =========================================================================
    // Helpers prives
    // =========================================================================

    /**
     * Ecrit le snapshot du pointage en fichier JSON local.
     * Chemin : logs/sessions/YYYY-MM/session-YYYY-MM-DD-{first8uuid}.json
     *
     * Cree le dossier si besoin. Si le fichier existe deja (rare : rejeu),
     * il est ecrase proprement. Encodage : JSON pretty-print UTF-8 pour
     * pouvoir l'ouvrir dans un editeur via SSH au besoin.
     */
    private function writeFileLog(string $sessionDate, string $sessionId, array $details): void
    {
        try {
            $month = substr($sessionDate, 0, 7); // "YYYY-MM"
            $dir   = self::LOG_DIR . '/' . $month;

            if (!is_dir($dir)) {
                // 0755 : lisible tous, ecrit user PHP uniquement. recursive=true
                // pour creer aussi "sessions" si on part de zero.
                mkdir($dir, 0755, true);
            }

            $filename = $dir . '/session-' . $sessionDate . '-' . substr($sessionId, 0, 8) . '.json';
            $content  = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($filename, $content);
        } catch (\Throwable $e) {
            // KO ecriture fichier => on n'interrompt pas la reponse HTTP.
            // BDD deja a jour, on log juste en interne.
            error_log('[AttendanceController] Erreur ecriture fichier log : ' . $e->getMessage());
        }
    }
}
