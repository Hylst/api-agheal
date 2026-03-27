<?php
/**
 * cron_purge_logs.php
 * GDPR Log Rotation Script – à exécuter le 1er de chaque mois à 01h00 via CRON
 * 
 * Cronjob (Coolify / serveur) :
 *   0 1 1 * * php /var/www/html/scripts/cron_purge_logs.php >> /var/www/html/logs/purge.log 2>&1
 * 
 * Ce script :
 *  1. Détecte les données (présences + paiements) > 2 ans glissants en BDD et en fichiers JSON.
 *  2. Exporte ces données en CSV (archive).
 *  3. Envoie l'archive par e-mail à l'admin (via PHPMailer / SMTP Gmail).
 *  4. Supprime les enregistrements obsolètes en BDD et les fichiers JSON correspondants.
 */

if (php_sapi_name() !== 'cli') {
    die("Ce script ne peut être exécuté qu'en ligne de commande.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ─── Chargement .env ─────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// ─── Configuration ───────────────────────────────────────────────────────────
$cutoffDate   = date('Y-m-d', strtotime('-2 years'));  // Limite : 2 ans glissants
$adminEmail   = $_ENV['ADMIN_EMAIL']       ?? 'admin@example.com';
$smtpHost     = $_ENV['SMTP_HOST']         ?? 'smtp.gmail.com';
$smtpPort     = (int)($_ENV['SMTP_PORT']   ?? 587);
$smtpUser     = $_ENV['SMTP_USER']         ?? '';
$smtpPass     = $_ENV['SMTP_PASS']         ?? '';
$smtpFromName = $_ENV['SMTP_FROM_NAME']    ?? 'AGHeal';
$smtpFrom     = $_ENV['SMTP_FROM_EMAIL']   ?? $smtpUser;
$logsBasePath = __DIR__ . '/../logs/sessions';
$tmpDir       = sys_get_temp_dir();

echo "[PURGE] Début de la purge RGPD – seuil : données avant le {$cutoffDate}\n";

// ─── Connexion BDD ───────────────────────────────────────────────────────────
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo "[ERREUR] Connexion BDD impossible : " . $e->getMessage() . "\n";
    exit(1);
}

// ─── Fonctions utilitaires ───────────────────────────────────────────────────

/**
 * Génère un fichier CSV à partir d'un tableau de données et retourne le chemin du fichier.
 */
function generateCsv(array $rows, string $filename): string {
    global $tmpDir;
    $path = $tmpDir . DIRECTORY_SEPARATOR . $filename;
    $f = fopen($path, 'w');
    // BOM UTF-8 pour compatibilité Excel
    fputs($f, "\xEF\xBB\xBF");
    if (!empty($rows)) {
        fputcsv($f, array_keys($rows[0]), ';');
        foreach ($rows as $row) {
            fputcsv($f, $row, ';');
        }
    }
    fclose($f);
    return $path;
}

/**
 * Supprime récursivement un dossier.
 */
function deleteDirectory(string $dir): void {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

// ─── 1. Collecte des présences obsolètes (table `logs`) ──────────────────────
echo "\n[1] Récupération des logs de présence antérieurs au {$cutoffDate}...\n";

$stmtLogs = $db->prepare("SELECT * FROM logs WHERE created_at < ?");
$stmtLogs->execute([$cutoffDate . ' 00:00:00']);
$oldLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
echo "    -> " . count($oldLogs) . " enregistrement(s) de présence trouvé(s).\n";

// ─── 2. Collecte des paiements obsolètes (table `payments_history`) ──────────
echo "\n[2] Récupération des règlements antérieurs au {$cutoffDate}...\n";

$stmtPayments = $db->prepare("SELECT * FROM payments_history WHERE payment_date < ?");
$stmtPayments->execute([$cutoffDate]);
$oldPayments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
echo "    -> " . count($oldPayments) . " règlement(s) trouvé(s).\n";

// ─── 3. Détection des fichiers JSON de sessions obsolètes ────────────────────
echo "\n[3] Recherche des fichiers de séances JSON > 2 ans...\n";

$oldJsonDirs = [];
if (is_dir($logsBasePath)) {
    $years = scandir($logsBasePath);
    foreach ($years as $year) {
        if (!is_numeric($year)) continue;
        $yearPath = $logsBasePath . DIRECTORY_SEPARATOR . $year;
        if (!is_dir($yearPath)) continue;
        $months = scandir($yearPath);
        foreach ($months as $month) {
            if (!preg_match('/^\d{4}-\d{2}$/', $year . '-' . $month) && !preg_match('/^\d{2}$/', $month)) continue;
            $folderDate = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
            if ($folderDate < $cutoffDate) {
                $oldJsonDirs[] = $yearPath . DIRECTORY_SEPARATOR . $month;
            }
        }
    }
}
echo "    -> " . count($oldJsonDirs) . " dossier(s) de séances JSON obsolète(s) trouvé(s).\n";

// ─── Vérification : y a-t-il quelque chose à purger ? ────────────────────────
if (empty($oldLogs) && empty($oldPayments) && empty($oldJsonDirs)) {
    echo "\n[OK] Aucune donnée obsolète à purger. Script terminé.\n";
    exit(0);
}

// ─── 4. Génération des archives CSV ──────────────────────────────────────────
echo "\n[4] Génération des archives CSV...\n";

$attachments = [];

if (!empty($oldLogs)) {
    $csvLogs = generateCsv($oldLogs, 'archive_presences_' . date('Y-m-d') . '.csv');
    $attachments[] = $csvLogs;
    echo "    -> Archive présences : {$csvLogs}\n";
}

if (!empty($oldPayments)) {
    $csvPayments = generateCsv($oldPayments, 'archive_reglements_' . date('Y-m-d') . '.csv');
    $attachments[] = $csvPayments;
    echo "    -> Archive règlements : {$csvPayments}\n";
}

// ─── 5. Envoi de l'email à l'admin avec pièces jointes ───────────────────────
echo "\n[5] Envoi de l'email récapitulatif à {$adminEmail}...\n";

$dateStr       = date('d/m/Y');
$nbLogs        = count($oldLogs);
$nbPayments    = count($oldPayments);
$nbJsonFolders = count($oldJsonDirs);

$bodyHtml = "
<html><body style='font-family: Arial, sans-serif;'>
<h2>🗂️ AGHeal – Purge RGPD automatique du {$dateStr}</h2>
<p>Ce message confirme la suppression automatique des données personnelles conservées depuis plus de <strong>2 ans</strong> (seuil légal : <strong>{$cutoffDate}</strong>).</p>
<h3>📊 Résumé des données supprimées</h3>
<ul>
  <li>✅ <strong>{$nbLogs}</strong> enregistrement(s) de présence (table <code>logs</code>)</li>
  <li>✅ <strong>{$nbPayments}</strong> historique(s) de règlement (table <code>payments_history</code>)</li>
  <li>✅ <strong>{$nbJsonFolders}</strong> dossier(s) de fichiers JSON de séances</li>
</ul>
<p>⚠️ Les données archivées sont jointes à cet email en CSV. Conservez-les selon vos obligations légales.</p>
<p><em>Ce message est généré automatiquement par le script de purge RGPD d'AGHeal.</em></p>
</body></html>";

$emailSuccess = false;
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host        = $smtpHost;
    $mail->SMTPAuth    = true;
    $mail->Username    = $smtpUser;
    $mail->Password    = $smtpPass;
    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port        = $smtpPort;
    $mail->CharSet     = 'UTF-8';

    $mail->setFrom($smtpFrom, $smtpFromName);
    $mail->addAddress($adminEmail);
    $mail->Subject = "[AGHeal] Purge RGPD automatique – {$dateStr} – Archive jointe";
    $mail->isHTML(true);
    $mail->Body    = $bodyHtml;
    $mail->AltBody = strip_tags(str_replace(['<li>', '</li>'], ["\n- ", ''], $bodyHtml));

    foreach ($attachments as $filePath) {
        if (file_exists($filePath)) {
            $mail->addAttachment($filePath, basename($filePath));
        }
    }

    $mail->send();
    $emailSuccess = true;
    echo "    -> Email envoyé avec succès à {$adminEmail}.\n";
} catch (PHPMailerException $e) {
    echo "    [AVERTISSEMENT] Envoi email échoué : " . $e->getMessage() . "\n";
    echo "    La suppression sera quand même effectuée. Vérifiez votre configuration SMTP.\n";
}

// ─── 6. Suppression des données en BDD ───────────────────────────────────────
echo "\n[6] Suppression des données obsolètes en base de données...\n";

if (!empty($oldLogs)) {
    $stmtDelLogs = $db->prepare("DELETE FROM logs WHERE created_at < ?");
    $stmtDelLogs->execute([$cutoffDate . ' 00:00:00']);
    echo "    -> {$nbLogs} enregistrement(s) de présence supprimé(s).\n";
}

if (!empty($oldPayments)) {
    $stmtDelPayments = $db->prepare("DELETE FROM payments_history WHERE payment_date < ?");
    $stmtDelPayments->execute([$cutoffDate]);
    echo "    -> {$nbPayments} règlement(s) supprimé(s).\n";
}

// ─── 7. Suppression des fichiers JSON de séances obsolètes ───────────────────
echo "\n[7] Suppression des dossiers de séances JSON obsolètes...\n";

foreach ($oldJsonDirs as $dir) {
    deleteDirectory($dir);
    echo "    -> Supprimé : {$dir}\n";
}

// ─── 8. Nettoyage des fichiers temporaires ────────────────────────────────────
foreach ($attachments as $tmpFile) {
    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
}

echo "\n[PURGE] ✅ Terminé avec succès le " . date('Y-m-d H:i:s') . "\n";
exit(0);
