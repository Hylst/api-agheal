<?php
namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailerService
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $fromEmail;
    private $fromName;
    private $appName;

    public function __construct()
    {
        $this->host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $this->port = getenv('SMTP_PORT') ?: 465;
        $this->username = getenv('SMTP_USER');
        $this->password = getenv('SMTP_PASS');
        $this->fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'noreply@agheal.fr';
        $this->fromName = getenv('SMTP_FROM_NAME') ?: 'AGheal';
        $this->appName = getenv('APP_NAME') ?: 'AGheal';
    }

    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->host;
        $mail->SMTPAuth = true;
        
        if ($this->username && $this->password) {
            $mail->Username = $this->username;
            $mail->Password = $this->password;
        }
        
        // Gmail utilise généralement le port 465 en SMTPS/SSL ou 587 en STARTTLS
        if ($this->port == 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        
        $mail->Port = $this->port;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom($this->fromEmail, $this->fromName);
        return $mail;
    }

    /**
     * Envoie l'e-mail de rappel de séance pour les adhérents.
     */
    public function sendSessionReminder(string $toEmail, string $firstName, array $sessionData): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $firstName);
            
            $mail->isHTML(true);
            $mail->Subject = "Rappel : Votre séance {$sessionData['title']} de demain";
            
            $date = date('d/m/Y', strtotime($sessionData['date']));
            $startTime = date('H:i', strtotime($sessionData['start_time']));
            $endTime = date('H:i', strtotime($sessionData['end_time']));
            
            $body = "<h2>Bonjour $firstName,</h2>";
            $body .= "<p>Ceci est un rappel pour votre séance prévue demain :</p>";
            $body .= "<ul>";
            $body .= "<li><strong>Séance :</strong> {$sessionData['title']}</li>";
            $body .= "<li><strong>Date :</strong> {$date}</li>";
            $body .= "<li><strong>Heure :</strong> {$startTime} - {$endTime}</li>";
            if (!empty($sessionData['location_name'])) {
                $body .= "<li><strong>Lieu :</strong> {$sessionData['location_name']}</li>";
            }
            if (!empty($sessionData['equipment_clients'])) {
                $body .= "<li><strong>Matériel requis :</strong> {$sessionData['equipment_clients']}</li>";
            }
            $body .= "</ul>";
            $body .= "<p>Si vous ne pouvez plus y assister, merci de vous désinscrire depuis votre espace adhérent pour libérer la place.</p>";
            $body .= "<br><p>À demain !</p>";
            $body .= "<p>L'équipe {$this->appName}</p>";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</li>'], ["\n", "\n"], $body));
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Session Reminder): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Envoie un rappel aux coachs pour les séances qu'ils animent le lendemain.
     */
    public function sendCoachScheduleReminder(string $toEmail, string $firstName, array $sessions): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $firstName);
            
            $mail->isHTML(true);
            $mail->Subject = "Vos séances à animer demain";
            
            $body = "<h2>Bonjour $firstName,</h2>";
            $body .= "<p>Voici le récapitulatif des séances que vous animez demain :</p>";
            $body .= "<ul>";
            
            foreach ($sessions as $session) {
                $startTime = date('H:i', strtotime($session['start_time']));
                $endTime = date('H:i', strtotime($session['end_time']));
                $body .= "<li><strong>{$startTime} - {$endTime} :</strong> {$session['title']} ";
                $body .= "({$session['registrations_count']}/{$session['max_people']} inscrits)</li>";
            }
            
            $body .= "</ul>";
            $body .= "<p>Bonne journée !</p>";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</li>'], ["\n", "\n"], $body));
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Coach Reminder): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Envoie l'e-mail de rappel de renouvellement
     */
    public function sendRenewalReminder(string $toEmail, string $firstName, string $renewalDate): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $firstName);
            
            $mail->isHTML(true);
            $mail->Subject = "Rappel : Renouvellement de votre abonnement / adhésion";
            
            $date = date('d/m/Y', strtotime($renewalDate));
            
            $body = "<h2>Bonjour $firstName,</h2>";
            $body .= "<p>Nous espérons que vous appréciez vos séances avec nous.</p>";
            $body .= "<p>Ceci est un petit rappel automatique pour vous informer que la date de renouvellement de votre abonnement ou adhésion est prévue pour demain, le <strong>{$date}</strong>.</p>";
            $body .= "<p>Merci de vous rapprocher de votre coach lors de votre prochaine visite pour régulariser votre situation si ce n'est pas déjà fait.</p>";
            $body .= "<br><p>Sportivement,</p>";
            $body .= "<p>L'équipe {$this->appName}</p>";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Renewal Reminder): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Notifie les adhérents de la publication de nouvelles séances.
     */
    public function sendNewSessionsNotification(array $bccEmails, array $sessionsData): bool
    {
        if (empty($bccEmails) || empty($sessionsData)) {
            return false;
        }

        try {
            $mail = $this->getMailer();
            // On envoie le mail à "noreply" et on met tout le monde en copie cachée pour respecter la RGPD
            $mail->addAddress($this->fromEmail, $this->appName);
            foreach ($bccEmails as $email) {
                $mail->addBCC($email);
            }
            
            $count = count($sessionsData);
            $mail->isHTML(true);
            $mail->Subject = "Nouvelles séances disponibles : {$count} session(s) ajoutée(s) !";
            
            $body = "<h2>Bonjour,</h2>";
            $body .= "<p>Votre coach a publié de nouvelles séances sur le planning :</p>";
            $body .= "<ul>";
            
            // On limite l'affichage aux 5 premières si c'est une grosse duplication (ex: sur 12 semaines)
            $displayCount = min($count, 5);
            for ($i = 0; $i < $displayCount; $i++) {
                $session = $sessionsData[$i];
                $date = date('d/m/Y', strtotime($session['date']));
                $startTime = date('H:i', strtotime($session['start_time']));
                $body .= "<li><strong>{$session['title']}</strong> le {$date} à {$startTime}</li>";
            }
            
            $body .= "</ul>";
            
            if ($count > 5) {
                $body .= "<p><em>Et " . ($count - 5) . " autre(s) occurrence(s) sur le calendrier...</em></p>";
            }
            
            $body .= "<p>Connectez-vous dès maintenant sur l'application pour réserver votre place.</p>";
            $body .= "<br><p>À très vite,</p>";
            $body .= "<p>L'équipe {$this->appName}</p>";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</li>', '</p>'], ["\n", "\n", "\n\n"], $body));
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (New Sessions): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Envoie l'e-mail de rappel de renouvellement de certificat médical (M-1)
     */
    public function sendMedicalCertificateReminder(string $toEmail, string $firstName, string $certifDate): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $firstName);
            
            $mail->isHTML(true);
            $mail->Subject = "Rappel : Renouvellement de votre certificat médical à venir";
            
            $date = date('d/m/Y', strtotime($certifDate));
            
            $body = "<h2>Bonjour $firstName,</h2>";
            $body .= "<p>Pourriez-vous vérifier la validité de votre certificat d'aptitude médicale ?</p>";
            $body .= "<p>Sauf erreur de notre part, celui que vous nous avez fourni arrive à expiration dans un mois (le <strong>{$date}</strong>).</p>";
            $body .= "<p>Nous vous invitons à consulter votre médecin pour obtenir un nouveau certificat et à nous le remettre lors de votre prochaine venue.</p>";
            $body .= "<br><p>Sportivement,</p>";
            $body .= "<p>L'équipe {$this->appName}</p>";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Medical Certif): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Alerte un coach/admin qu'un ou plusieurs adhérents ont des paiements expirés.
     */
    public function sendExpiredPaymentAlert(string $toEmail, string $coachName, array $expiredClients): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $coachName);
            
            $mail->isHTML(true);
            $mail->Subject = "Alerte automatique : Abonnements arrivés à échéance";
            
            $body = "<h2>Bonjour $coachName,</h2>";
            $body .= "<p>Le système a automatiquement basculé le statut de règlement des adhérents suivants en \"En attente\", car leur date de renouvellement est dépassée :</p>";
            
            $body .= "<ul>";
            foreach ($expiredClients as $client) {
                $date = date('d/m/Y', strtotime($client['renewal_date']));
                $body .= "<li><strong>{$client['first_name']} {$client['last_name']}</strong> (échue le {$date})</li>";
            }
            $body .= "</ul>";
            
            $body .= "<p>Veuillez faire le point avec eux lors de leur prochaine séance.</p>";
            $body .= "<br><p>Le système {$this->appName}</p>";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</li>', '</p>'], ["\n", "\n", "\n\n"], $body));
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Expired Payment Alert): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Alerte un coach/admin pour vérification de documents (renouvellements, certificats).
     */
    public function sendDocumentVerificationAlert(string $toEmail, string $coachName, array $clients, string $reason): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $coachName);
            
            $mail->isHTML(true);
            $mail->Subject = "Vérification requise : $reason";
            
            $body = "<h2>Bonjour $coachName,</h2>";
            $body .= "<p>Les adhérents suivants nécessitent une vérification concernant : <strong>$reason</strong></p>";
            
            $body .= "<ul>";
            foreach ($clients as $client) {
                $name = $client['first_name'] . (isset($client['last_name']) ? ' ' . $client['last_name'] : '');
                $body .= "<li><strong>{$name}</strong></li>";
            }
            $body .= "</ul>";
            
            $body .= "<p>Veuillez faire le point avec eux lors de leur prochaine venue.</p>";
            $body .= "<br><p>Le système {$this->appName}</p>";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</li>', '</p>'], ["\n", "\n", "\n\n"], $body));
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Verification Alert): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Envoie un e-mail personnalisable (campagne d'e-mails)
     */
    public function sendCustomCampaign(string $toEmail, string $firstName, string $subject, string $content): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $firstName);
            
            $mail->isHTML(true);
            $mail->Subject = $subject;
            
            // Formatage du contenu avec des retours à la ligne HTML
            $formattedContent = nl2br(htmlspecialchars($content));
            
            $body = "<h2>Bonjour $firstName,</h2>";
            $body .= "<p>{$formattedContent}</p>";
            $body .= "<br><p>L'équipe {$this->appName}</p>";
            
            $mail->Body = $body;
            
            // Version AltBody (texte brut)
            $altBody = "Bonjour $firstName,\n\n";
            $altBody .= strip_tags($content) . "\n\n";
            $altBody .= "L'équipe {$this->appName}";
            
            $mail->AltBody = $altBody;
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Custom Campaign): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Envoie l'email de réinitialisation de mot de passe.
     * @param string $toEmail  Adresse de l'utilisateur
     * @param string $resetLink Lien complet avec token (valide 1h)
     */
    public function sendPasswordReset(string $toEmail, string $resetLink): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail);

            $mail->isHTML(true);
            $mail->Subject = "[{$this->appName}] Réinitialisation de votre mot de passe";

            $body  = "<h2>Réinitialisation de votre mot de passe</h2>";
            $body .= "<p>Vous avez demandé la réinitialisation de votre mot de passe sur <strong>{$this->appName}</strong>.</p>";
            $body .= "<p>Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe.<br>";
            $body .= "Ce lien est <strong>valable 1 heure</strong> et ne peut être utilisé qu'une seule fois.</p>";
            $body .= "<p style='margin:24px 0;'><a href='{$resetLink}' style='background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;'>Réinitialiser mon mot de passe</a></p>";
            $body .= "<p style='color:#888;font-size:12px;'>Si vous n'avez pas demandé cette réinitialisation, ignorez simplement cet email. Votre mot de passe ne sera pas modifié.</p>";
            $body .= "<p>L'équipe {$this->appName}</p>";

            $mail->Body    = $body;
            $mail->AltBody = "Réinitialisation de mot de passe {$this->appName}\n\nLien (valide 1h) :\n{$resetLink}\n\nSi vous n'avez pas fait cette demande, ignorez cet email.";

            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Password Reset): {$e->getMessage()}");
            return false;
        }
    }
}
