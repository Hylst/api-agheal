<?php
// src/Controllers/ContactController.php
namespace App\Controllers;

use App\Helpers\Sanitizer;
use App\Services\EmailService;

class ContactController
{
    public function send(): void
    {
        $data    = json_decode(file_get_contents('php://input'), true);
        $name    = Sanitizer::text($data['name']  ?? 'Anonyme', 100);
        $email   = Sanitizer::email($data['email']   ?? '');
        $message = Sanitizer::text($data['message'] ?? '', 2000);

        if (empty($email)) {
            http_response_code(422);
            echo json_encode(['error' => 'Adresse email invalide']);
            return;
        }

        $subject = "Nouveau message de contact : $name";
        $body  = "<h2>Nouveau message de contact</h2>";
        $body .= "<p><strong>Nom :</strong> $name</p>";
        $body .= "<p><strong>Email :</strong> " . htmlspecialchars($email) . "</p>";
        $body .= "<p><strong>Message :</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";

        $adminEmail = $_ENV['ADMIN_EMAIL'] ?? 'admin@example.com';

        if (EmailService::send($adminEmail, $subject, $body)) {
            http_response_code(200);
            echo json_encode(['message' => 'Message envoyé avec succès']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => "Echec de l'envoi de l'email"]);
        }
    }
}
