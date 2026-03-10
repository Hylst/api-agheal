<?php
// bin/send-reminders.php
// Script de rappel par email à lancer par cron (ex: une fois par jour à 8h00)

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/EmailService.php';

use App\Services\EmailService;
use Dotenv\Dotenv;

// Charger les variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialiser la DB
$db = Database::getInstance();

echo "[" . date('Y-m-d H:i:s') . "] Démarrage de l'envoi des rappels...\n";

try {
    // 1. Récupérer les séances de demain
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    $sql = "
        SELECT s.id, s.title, s.date, s.start_time, l.name as location_name, l.address as location_address
        FROM sessions s
        LEFT JOIN locations l ON l.id = s.location_id
        WHERE s.date = ? AND s.status = 'published'
    ";
    
    $stmt = $db->query($sql, [$tomorrow]);
    $sessions = $stmt->fetchAll();
    
    if (empty($sessions)) {
        echo "Aucune séance prévue pour demain ($tomorrow).\n";
        exit;
    }

    $emailsSent = 0;

    foreach ($sessions as $session) {
        // 2. Récupérer les inscrits pour chaque séance
        $regSql = "
            SELECT p.email, p.first_name, p.last_name
            FROM registrations r
            JOIN profiles p ON p.id = r.user_id
            WHERE r.session_id = ?
        ";
        $regStmt = $db->query($regSql, [$session['id']]);
        $registrations = $regStmt->fetchAll();

        foreach ($registrations as $reg) {
            $to = $reg['email'];
            $subject = "Rappel : Votre séance demain - " . $session['title'];
            
            $body = "
                <h2>Bonjour " . htmlspecialchars($reg['first_name']) . ",</h2>
                <p>Ceci est un petit rappel pour votre séance de sport de demain :</p>
                <ul>
                    <li><strong>Séance :</strong> " . htmlspecialchars($session['title']) . "</li>
                    <li><strong>Date :</strong> " . date('d/m/Y', strtotime($session['date'])) . "</li>
                    <li><strong>Heure :</strong> " . htmlspecialchars($session['start_time']) . "</li>
                    <li><strong>Lieu :</strong> " . htmlspecialchars($session['location_name']) . " (" . htmlspecialchars($session['location_address']) . ")</li>
                </ul>
                <p>À demain !</p>
                <p>L'équipe AGHeal</p>
            ";

            if (EmailService::send($to, $subject, $body)) {
                $emailsSent++;
            } else {
                echo "Erreur d'envoi à $to\n";
            }
        }
    }

    echo "Terminé. $emailsSent emails de rappel envoyés.\n";

} catch (Exception $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
