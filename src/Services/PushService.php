<?php
// src/Services/PushService.php
//
// Service d'envoi de notifications Web Push (VAPID).
// Wrap minishlink/web-push.
//
// Cle VAPID a generer une fois pour toutes (cf generate_vapid.js ou .php
// a la racine du repo). Les 2 cles vont dans .env (publique aussi cote front).
//
// Conso typique :
//   $push = new PushService();
//   $push->sendToUser($userId, 'Rappel seance', 'Demain a 18h', '/sessions/123');
//
// Multi-devices : un user peut avoir N abonnements push (1 par device).
// La methode boucle sur tous et nettoie les endpoints HTTP 410 (deads).
//
// Pre-requis serveur : extension PHP gmp OU bcmath (calculs crypto VAPID).

namespace App\Services;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Database;

class PushService
{
    private WebPush $webPush;

    public function __construct()
    {
        $auth = [
            'VAPID' => [
                // subject : URL ou mailto qui identifie l'app. Peut etre n'importe quoi
                // de valide, c'est juste informatif pour les push services (Google FCM, etc.).
                'subject' => 'mailto:geoffroy.streit.dev@gmail.com',
                'publicKey' => $_ENV['VAPID_PUBLIC_KEY'] ?? '',
                'privateKey' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
            ]
        ];

        $this->webPush = new WebPush($auth);
    }

    /**
     * Envoie une notification à un utilisateur spécifique (via tous ses devices)
     */
    public function sendToUser(string $userId, string $title, string $body, string $url = '/'): int
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM push_subscriptions WHERE user_id = ?", [$userId]);
        $subs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($subs)) {
            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url
            // On peut ajouter icône, badge, etc.
        ]);

        $successCount = 0;
        foreach ($subs as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh'],
                'authToken' => $sub['auth']
            ]);

            $res = $this->webPush->sendOneNotification($subscription, $payload);
            if ($res && $res->isSuccess()) {
                $successCount++;
            } else {
                // Si la souscription a expiré, on peut la supprimer de la DB
                if ($res && $res->isSubscriptionExpired()) {
                    $db->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$sub['id']]);
                }
            }
        }

        return $successCount;
    }

    public function close(): void
    {
        // Vide le pool si sendNotification a été utilisé en batch
        $this->webPush->flush();
    }
}
