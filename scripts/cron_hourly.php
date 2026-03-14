<?php
/**
 * cron_hourly.php
 * Script à exécuter fréquemment (ex: toutes les heures, ou toutes les 15 minutes) via CRON
 * 
 * Ce script :
 * 1. Lit les campagnes d'e-mails (table `email_campaigns`) dont le statut est 'pending'
 *    et dont la date de planification `scheduled_at` est passée (<= NOW()).
 * 2. Envoie les emails aux cibles concernées via MailerService.
 * 3. Met à jour le statut à 'sent' (ou 'failed').
 */

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
    $now = date('Y-m-d H:i:s');

    echo "[CRON HOURLY] Début du traitement des campagnes d'e-mails à {$now}\n";

    // Récupérer les campagnes en attente
    $stmt = $db->prepare("
        SELECT * FROM email_campaigns
        WHERE status = 'pending' AND scheduled_at <= :now
    ");
    $stmt->execute(['now' => $now]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($campaigns)) {
        echo " -> Aucune campagne d'e-mails en attente.\n";
        exit(0);
    }

    echo " -> " . count($campaigns) . " campagne(s) trouvée(s).\n";

    foreach ($campaigns as $campaign) {
        $campaignId = $campaign['id'];
        $subject = $campaign['subject'];
        $content = $campaign['content'];
        $targetType = $campaign['target_type'];
        $targetId = $campaign['target_id'];

        echo "    Traitement de la campagne #{$campaignId} : {$subject} (Cible: {$targetType})\n";

        // Récupérer les destinataires
        $recipients = [];

        if ($targetType === 'all') {
            $stmtUsers = $db->query("SELECT id, first_name, email FROM profiles WHERE email IS NOT NULL AND email != ''");
            $recipients = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($targetType === 'group' && $targetId) {
            $stmtUsers = $db->prepare("
                SELECT p.id, p.first_name, p.email 
                FROM profiles p
                JOIN user_groups ug ON p.id = ug.user_id
                WHERE ug.group_id = :group_id AND p.email IS NOT NULL AND p.email != ''
            ");
            $stmtUsers->execute(['group_id' => $targetId]);
            $recipients = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($targetType === 'user' && $targetId) {
            $stmtUser = $db->prepare("SELECT id, first_name, email FROM profiles WHERE id = :user_id AND email IS NOT NULL AND email != ''");
            $stmtUser->execute(['user_id' => $targetId]);
            $recipients = $stmtUser->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($recipients)) {
            echo "      Aucun destinataire valide pour cette campagne. Passage en 'failed'.\n";
            $db->prepare("UPDATE email_campaigns SET status = 'failed' WHERE id = ?")->execute([$campaignId]);
            continue;
        }

        // On envoie le mail à chaque destinataire individuellement (ou en BCC, ici individuel pour personnaliser le prénom)
        $sentCount = 0;
        foreach ($recipients as $recipient) {
            $success = $mailer->sendCustomCampaign($recipient['email'], $recipient['first_name'], $subject, $content);
            if ($success) {
                $sentCount++;
            }
        }

        echo "      -> {$sentCount}/" . count($recipients) . " e-mails envoyés.\n";

        // Marquer la campagne comme envoyée
        $db->prepare("UPDATE email_campaigns SET status = 'sent' WHERE id = ?")->execute([$campaignId]);
    }

    echo "\n[CRON HOURLY] Terminé.\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[ERREUR CRITIQUE] " . $e->getMessage() . "\n";
    exit(1);
}
