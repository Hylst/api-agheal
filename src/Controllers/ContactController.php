<?php
// src/Controllers/ContactController.php
require_once __DIR__ . '/../Services/EmailService.php';
use App\Services\EmailService;

class ContactController
{
    public function send(): void
    {
        $data    = json_decode(file_get_contents('php://input'), true);
        $name    = htmlspecialchars($data['name']    ?? 'Anonyme');
        $email   = trim($data['email']   ?? '');
        $message = trim($data['message'] ?? '');

        if (empty($email) || empty($message)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email et message requis']);
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
