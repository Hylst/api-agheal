<?php
/**
 * cron_daily.php
 * Script à exécuter quotidiennement (ex: 07h00) via CRON
 * 
 * Ce script :
 * 1. Vérifie les renouvellements d'abonnements pour le lendemain et notifie les adhérents concernés.
 * 2. Liste les séances du lendemain et envoie un rappel aux adhérents inscrits.
 * 3. Envoie un récapitulatif des séances du lendemain aux coachs concernés.
 */

// Permet l'exécution en CLI uniquement pour des raisons de sécurité (optionnel mais recommandé)
if (php_sapi_name() !== 'cli') {
    die("Ce script ne peut être exécuté qu'en ligne de commande.");
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/MailerService.php';

use App\Services\MailerService;
use Dotenv\Dotenv;

// Charger les variables d'environnement
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

try {
    $db = Database::getInstance();
    $mailer = new MailerService();
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    echo "[CRON] Début du traitement pour la date : {$tomorrow}\n";

    // =========================================================================
    // 1. RAPPELS DE RENOUVELLEMENT
    // =========================================================================
    echo "\n[1] Traitement des rappels de renouvellement...\n";
    $stmtRenewal = $db->prepare("
        SELECT id, first_name, email 
        FROM profiles 
        WHERE renewal_date = :tomorrow 
          AND notify_renewal_reminder_email = 1
    ");
    $stmtRenewal->execute(['tomorrow' => $tomorrow]);
    $renewals = $stmtRenewal->fetchAll(PDO::FETCH_ASSOC);

    $renewalsSent = 0;
    foreach ($renewals as $user) {
        if (!empty($user['email'])) {
            $success = $mailer->sendRenewalReminder($user['email'], $user['first_name'], $tomorrow);
            if ($success) {
                $renewalsSent++;
            }
        }
    }
    echo "    -> {$renewalsSent} email(s) de renouvellement envoyé(s) sur " . count($renewals) . ".\n";

    // =========================================================================
    // 2. RAPPELS DE SÉANCES POUR LES ADHÉRENTS
    // =========================================================================
    echo "\n[2] Traitement des rappels de séances (Adhérents)...\n";
    // On cherche les adhérents inscrits à une séance de demain qui ont activé l'option
    $stmtSessionsClient = $db->prepare("
        SELECT 
            p.email, p.first_name,
            s.title, s.date, s.start_time, s.end_time, s.equipment_clients,
            l.name as location_name
        FROM registrations r
        JOIN profiles p ON r.user_id = p.id
        JOIN sessions s ON r.session_id = s.id
        LEFT JOIN locations l ON s.location_id = l.id
        WHERE s.date = :tomorrow
          AND p.notify_session_reminder_email = 1
          AND s.status = 'published'
    ");
    $stmtSessionsClient->execute(['tomorrow' => $tomorrow]);
    $clientSessions = $stmtSessionsClient->fetchAll(PDO::FETCH_ASSOC);

    $clientRemindersSent = 0;
    foreach ($clientSessions as $row) {
        if (!empty($row['email'])) {
            $success = $mailer->sendSessionReminder($row['email'], $row['first_name'], $row);
            if ($success) {
                $clientRemindersSent++;
            }
        }
    }
    echo "    -> {$clientRemindersSent} rappel(s) de séance envoyé(s) aux adhérents sur " . count($clientSessions) . ".\n";

    // =========================================================================
    // 3. RÉCAPITULATIF POUR LES COACHS
    // =========================================================================
    echo "\n[3] Récapitulatif du planning pour les Coachs...\n";
    // Pour chaque coach ayant des séances demain et l'option activée
    $stmtCoachs = $db->prepare("
        SELECT p.id, p.first_name, p.email
        FROM profiles p
        JOIN user_roles ur ON p.id = ur.user_id
        WHERE ur.role IN ('admin', 'coach') 
          AND p.notify_scheduled_sessions_email = 1
          AND EXISTS (
              SELECT 1 FROM sessions s 
              WHERE s.created_by = p.id AND s.date = :tomorrow AND s.status = 'published'
          )
    ");
    $stmtCoachs->execute(['tomorrow' => $tomorrow]);
    $coachs = $stmtCoachs->fetchAll(PDO::FETCH_ASSOC);

    $coachRemindersSent = 0;
    foreach ($coachs as $coach) {
        if (empty($coach['email'])) continue;

        // Récupérer les séances de ce coach pour demain
        $stmtCoachSessions = $db->prepare("
            SELECT s.title, s.start_time, s.end_time, s.max_people,
                   (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id) as registrations_count
            FROM sessions s
            WHERE s.created_by = :coach_id
              AND s.date = :tomorrow
              AND s.status = 'published'
            ORDER BY s.start_time ASC
        ");
        $stmtCoachSessions->execute(['coach_id' => $coach['id'], 'tomorrow' => $tomorrow]);
        $sessions = $stmtCoachSessions->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($sessions)) {
            $success = $mailer->sendCoachScheduleReminder($coach['email'], $coach['first_name'], $sessions);
            if ($success) {
                $coachRemindersSent++;
            }
        }
    }
    echo "    -> {$coachRemindersSent} récapitulatif(s) envoyé(s) aux coachs sur " . count($coachs) . ".\n";

    echo "\n[CRON] Terminé avec succès.\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[ERREUR CRITIQUE] " . $e->getMessage() . "\n";
    exit(1);
}
