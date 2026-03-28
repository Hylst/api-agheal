<?php
// src/Controllers/PushController.php
namespace App\Controllers;

use Database;
use Auth;

class PushController
{
    /**
     * POST /push/subscribe
     * Enregistre un nouvel abonnement Push VAPID pour l'utilisateur courant
     */
    public function subscribe(): void
    {
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Format d\'abonnement Push invalide']);
            return;
        }

        $endpoint = $data['endpoint'];
        $p256dh = $data['keys']['p256dh'];
        $auth = $data['keys']['auth'];

        $db = Database::getInstance();

        // On vérifie si cet endpoint existe déjà pour cet utilisateur
        $stmt = $db->prepare("SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
        $stmt->execute([$userId, $endpoint]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Mettre à jour au cas où les clés auraient changé (rare mais possible)
            $update = $db->prepare("UPDATE push_subscriptions SET p256dh = ?, auth = ? WHERE id = ?");
            $update->execute([$p256dh, $auth, $existing['id']]);
        } else {
            // Insérer le nouvel abonnement
            $insert = $db->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
            $insert->execute([$userId, $endpoint, $p256dh, $auth]);
        }

        http_response_code(200);
        echo json_encode(['success' => true]);
    }

    /**
     * POST /push/unsubscribe
     * Supprime un abonnement Push
     */
    public function unsubscribe(): void
    {
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['endpoint'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Endpoint manquant']);
            return;
        }

        $endpoint = $data['endpoint'];
        $db = Database::getInstance();

        $delete = $db->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
        $delete->execute([$userId, $endpoint]);

        http_response_code(200);
        echo json_encode(['success' => true]);
    }
}
