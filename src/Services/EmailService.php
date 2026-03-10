<?php
// src/Services/EmailService.php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    public static function send($to, $subject, $body)
    {
        $mail = new PHPMailer(true);

        try {
            // Configuration SMTP (à remplir avec les infos du serveur de prod ou de test)
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'localhost';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'] ?? '';
            $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

            // Destinataires
            $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@agheal.fr', 'AGHeal');
            $mail->addAddress($to);

            // Contenu
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->CharSet = 'UTF-8';

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}
