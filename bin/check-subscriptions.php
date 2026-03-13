<?php
// bin/check-subscriptions.php
// Script de vérification des abonnements et rappels de renouvellement
// À lancer par cron (ex: une fois par jour à 9h00)

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

echo "[" . date('Y-m-d H:i:s') . "] Vérification des abonnements...\n";

try {
    $today = date('Y-m-d');
    $reminderDate = date('Y-m-d', strtotime('+7 days')); // Rappel 7 jours avant

    // 1. Identifier les abonnements qui expirent dans 7 jours (Rappel)
    $sqlReminder = "
        SELECT p.id, p.email, p.first_name, p.last_name, p.renewal_date
        FROM profiles p
        WHERE p.renewal_date = ? AND p.payment_status = 'a_jour'
    ";
    $stmt = $db->query($sqlReminder, [$reminderDate]);
    $toRemind = $stmt->fetchAll();

    $remindersSent = 0;
    foreach ($toRemind as $user) {
        $to = $user['email'];
        $subject = "Rappel : Renouvellement de votre abonnement AGHeal";
        $body = "
            <h2>Bonjour " . htmlspecialchars($user['first_name']) . ",</h2>
            <p>Votre abonnement AGHeal arrive à échéance le <strong>" . date('d/m/Y', strtotime($user['renewal_date'])) . "</strong> (dans 7 jours).</p>
            <p>Pensez à régulariser votre situation auprès de votre coach pour continuer à profiter de nos services sans interruption.</p>
            <p>À bientôt !</p>
            <p>L'équipe AGHeal</p>
        ";

        if (EmailService::send($to, $subject, $body)) {
            $remindersSent++;
        }
    }
    echo "$remindersSent rappels de renouvellement envoyés.\n";

    // 2. Identifier les abonnements expirés (Aujourd'hui ou avant) et mettre à jour le statut
    // On ne touche qu'à ceux qui sont encore 'a_jour'
    $sqlExpired = "
        UPDATE profiles
        SET payment_status = 'en_attente'
        WHERE renewal_date <= ? AND payment_status = 'a_jour'
    ";
    $stmtExpired = $db->query($sqlExpired, [$today]);
    $expiredCount = $stmtExpired->rowCount();

    echo "$expiredCount abonnements passés en 'En attente' suite à expiration.\n";

    echo "[" . date('Y-m-d H:i:s') . "] Vérification terminée.\n";

} catch (Exception $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
