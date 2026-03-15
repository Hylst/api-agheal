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
require_once __DIR__ . '/../src/Services/PushService.php';

use App\Services\MailerService;
use App\Services\PushService;
use Dotenv\Dotenv;

// Charger les variables d'environnement
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

try {
    $db = Database::getInstance();
    $mailer = new MailerService();
    $pusher = new PushService();
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $nextMonth = date('Y-m-d', strtotime('+1 month'));

    // Charger les coachs opt-in pour la vérification documents/paiements
    $stmtVerifyCoachs = $db->prepare("
        SELECT p.id, p.first_name, p.email, p.notify_renewal_verify_email, p.notify_renewal_verify_push
        FROM profiles p
        JOIN user_roles ur ON p.id = ur.user_id
        WHERE ur.role IN ('admin', 'coach') 
          AND (p.notify_renewal_verify_email = 1 OR p.notify_renewal_verify_push = 1)
    ");
    $stmtVerifyCoachs->execute();
    $verifyCoachs = $stmtVerifyCoachs->fetchAll(PDO::FETCH_ASSOC);

    echo "[CRON] Début du traitement pour la date : {$today} (Demain: {$tomorrow}, M+1: {$nextMonth})\n";

    // =========================================================================
    // 1. RAPPELS DE RENOUVELLEMENT
    // =========================================================================
    echo "\n[1] Traitement des rappels de renouvellement...\n";
    $stmtRenewal = $db->prepare("
        SELECT id, first_name, last_name, email, notify_renewal_reminder_email, notify_renewal_reminder_push
        FROM profiles 
        WHERE renewal_date = :tomorrow
    ");
    $stmtRenewal->execute(['tomorrow' => $tomorrow]);
    $renewals = $stmtRenewal->fetchAll(PDO::FETCH_ASSOC);

    $renewalsSent = 0;
    foreach ($renewals as $user) {
        if ($user['notify_renewal_reminder_email'] && !empty($user['email'])) {
            $success = $mailer->sendRenewalReminder($user['email'], $user['first_name'], $tomorrow);
            if ($success) $renewalsSent++;
        }
        if ($user['notify_renewal_reminder_push']) {
            $pusher->sendToUser($user['id'], 'Renouvellement AGHeal', "Bonjour {$user['first_name']}, votre abonnement sport/coaching doit être renouvelé demain.");
        }
    }
    echo "    -> {$renewalsSent} email(s) de renouvellement envoyé(s) sur " . count($renewals) . ".\n";

    if (count($renewals) > 0) {
        $verifyCount = 0;
        foreach ($verifyCoachs as $coach) {
            if ($coach['notify_renewal_verify_email'] && !empty($coach['email'])) {
                $mailer->sendDocumentVerificationAlert($coach['email'], $coach['first_name'], $renewals, "Renouvellement d'abonnement (Demain)");
                $verifyCount++;
            }
            if ($coach['notify_renewal_verify_push']) {
                $pusher->sendToUser($coach['id'], 'Vérification Requise', count($renewals) . " adhérent(s) renouvellent demain. Préparez-vous à vérifier.");
            }
        }
        echo "    -> {$verifyCount} alerte(s) de vérification envoyée(s) aux coachs.\n";
    }

    // =========================================================================
    // 2. RAPPELS DE SÉANCES POUR LES ADHÉRENTS
    // =========================================================================
    echo "\n[2] Traitement des rappels de séances (Adhérents)...\n";
    // On cherche les adhérents inscrits à une séance de demain qui ont activé l'option
    $stmtSessionsClient = $db->prepare("
        SELECT 
            p.id as user_id, p.email, p.first_name, p.notify_session_reminder_email, p.notify_session_reminder_push,
            s.title, s.date, s.start_time, s.end_time, s.equipment_clients,
            l.name as location_name
        FROM registrations r
        JOIN profiles p ON r.user_id = p.id
        JOIN sessions s ON r.session_id = s.id
        LEFT JOIN locations l ON s.location_id = l.id
        WHERE s.date = :tomorrow
          AND (p.notify_session_reminder_email = 1 OR p.notify_session_reminder_push = 1)
          AND s.status = 'published'
    ");
    $stmtSessionsClient->execute(['tomorrow' => $tomorrow]);
    $clientSessions = $stmtSessionsClient->fetchAll(PDO::FETCH_ASSOC);

    $clientRemindersSent = 0;
    foreach ($clientSessions as $row) {
        if ($row['notify_session_reminder_email'] && !empty($row['email'])) {
            $success = $mailer->sendSessionReminder($row['email'], $row['first_name'], $row);
            if ($success) $clientRemindersSent++;
        }
        if ($row['notify_session_reminder_push']) {
            $h = substr($row['start_time'], 0, 5);
            $pusher->sendToUser($row['user_id'], 'Rappel de Séance', "Demain à {$h} : {$row['title']} - {$row['location_name']}");
        }
    }
    echo "    -> {$clientRemindersSent} rappel(s) de séance envoyé(s) aux adhérents sur " . count($clientSessions) . ".\n";

    // =========================================================================
    // 3. RÉCAPITULATIF POUR LES COACHS
    // =========================================================================
    echo "\n[3] Récapitulatif du planning pour les Coachs...\n";
    // Pour chaque coach ayant des séances demain et l'option activée
    $stmtCoachs = $db->prepare("
        SELECT p.id, p.first_name, p.email, p.notify_scheduled_sessions_email, p.notify_scheduled_sessions_push
        FROM profiles p
        JOIN user_roles ur ON p.id = ur.user_id
        WHERE ur.role IN ('admin', 'coach') 
          AND (p.notify_scheduled_sessions_email = 1 OR p.notify_scheduled_sessions_push = 1)
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
            if ($coach['notify_scheduled_sessions_email'] && !empty($coach['email'])) {
                $success = $mailer->sendCoachScheduleReminder($coach['email'], $coach['first_name'], $sessions);
                if ($success) $coachRemindersSent++;
            }
            if ($coach['notify_scheduled_sessions_push']) {
                $sCount = count($sessions);
                $plural = $sCount > 1 ? 's' : '';
                $pusher->sendToUser($coach['id'], 'Planning de demain', "Vous avez {$sCount} séance{$plural} programmée{$plural} demain.");
            }
        }
    }
    echo "    -> {$coachRemindersSent} récapitulatif(s) envoyé(s) aux coachs sur " . count($coachs) . ".\n";

    // =========================================================================
    // 4. CERTIFICATS MÉDICAUX (M-1)
    // =========================================================================
    echo "\n[4] Traitement des certificats médicaux à M-1...\n";
    $stmtCertif = $db->prepare("
        SELECT id, first_name, last_name, email, medical_certificate_date, notify_medical_certif_email, notify_medical_certif_push
        FROM profiles 
        WHERE medical_certificate_date = :nextMonth
    ");
    $stmtCertif->execute(['nextMonth' => $nextMonth]);
    $certifs = $stmtCertif->fetchAll(PDO::FETCH_ASSOC);

    $certifsSent = 0;
    foreach ($certifs as $user) {
        if ($user['notify_medical_certif_email'] && !empty($user['email'])) {
            $success = $mailer->sendMedicalCertificateReminder($user['email'], $user['first_name'], $user['medical_certificate_date']);
            if ($success) $certifsSent++;
        }
        if ($user['notify_medical_certif_push']) {
            $pusher->sendToUser($user['id'], 'Certificat Médical', "Attention {$user['first_name']}, votre certificat expire le {$user['medical_certificate_date']}.");
        }
    }
    echo "    -> {$certifsSent} rappel(s) de certificat médical envoyé(s) sur " . count($certifs) . ".\n";

    if (count($certifs) > 0) {
        $verifyCount = 0;
        foreach ($verifyCoachs as $coach) {
            if ($coach['notify_renewal_verify_email'] && !empty($coach['email'])) {
                $mailer->sendDocumentVerificationAlert($coach['email'], $coach['first_name'], $certifs, "Renouvellement de Certificat Médical (M-1)");
                $verifyCount++;
            }
            if ($coach['notify_renewal_verify_push']) {
                $pusher->sendToUser($coach['id'], 'Vérification Requise', count($certifs) . " adhérent(s) doivent renouveler leur certif. méd. le mois prochain.");
            }
        }
        echo "    -> {$verifyCount} alerte(s) de vérification de certificat envoyée(s) aux coachs.\n";
    }

    // =========================================================================
    // 5. EXPIRATION DES PAIEMENTS (J+1) ET ALERTES COACHS
    // =========================================================================
    echo "\n[5] Traitement des expirations de paiement...\n";
    // On cherche les adhérents dont la date est passée ET qui ne sont pas déjà 'en_attente'
    $stmtExpired = $db->prepare("
        SELECT id, first_name, last_name, renewal_date
        FROM profiles
        WHERE renewal_date < :today
          AND payment_status != 'en_attente'
    ");
    $stmtExpired->execute(['today' => $today]);
    $expiredClients = $stmtExpired->fetchAll(PDO::FETCH_ASSOC);

    if (count($expiredClients) > 0) {
        // Mettre à jour le statut en base
        $ids = array_column($expiredClients, 'id');
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        
        $updateStmt = $db->prepare("UPDATE profiles SET payment_status = 'en_attente' WHERE id IN ($inQuery)");
        $updateStmt->execute($ids);
        echo "    -> " . count($expiredClients) . " adhérent(s) passé(s) en statut 'en_attente'.\n";

        // Alerter les coachs/admins qui ont coché l'option
        $stmtAlertCoachs = $db->prepare("
            SELECT p.id, p.first_name, p.email, p.notify_expired_payment_email, p.notify_expired_payment_push
            FROM profiles p
            JOIN user_roles ur ON p.id = ur.user_id
            WHERE ur.role IN ('admin', 'coach') 
              AND (p.notify_expired_payment_email = 1 OR p.notify_expired_payment_push = 1)
        ");
        $stmtAlertCoachs->execute();
        $alertCoachs = $stmtAlertCoachs->fetchAll(PDO::FETCH_ASSOC);

        $alertsSent = 0;
        foreach ($alertCoachs as $coach) {
            if ($coach['notify_expired_payment_email'] && !empty($coach['email'])) {
                $success = $mailer->sendExpiredPaymentAlert($coach['email'], $coach['first_name'], $expiredClients);
                if ($success) $alertsSent++;
            }
            if ($coach['notify_expired_payment_push']) {
                $c = count($expiredClients);
                $pusher->sendToUser($coach['id'], 'Alerte Expiration', "{$c} abonnement(s) ont expiré aujourd'hui (Paiement en attente).");
            }
        }
        echo "    -> {$alertsSent} alerte(s) d'expiration envoyée(s) aux coachs (Email/Push).\n";
    } else {
        echo "    -> Aucun nouveau paiement expiré aujourd'hui.\n";
    }

    echo "\n[CRON] Terminé avec succès.\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[ERREUR CRITIQUE] " . $e->getMessage() . "\n";
    exit(1);
}
