<?php
// src/Controllers/RegistrationController.php
namespace App\Controllers;

use Auth;
use Exception;
use App\Repositories\RegistrationRepository;

/**
 * Ce contrôleur gère les inscriptions et désinscriptions aux séances.
 * Conformément à la refactorisation (Pattern Repository), 
 * il ne contient plus de requêtes SQL brutes.
 */
class RegistrationController
{
    private RegistrationRepository $registrations;

    public function __construct()
    {
        // Instanciation du Repository qui va s'occuper de la base de données
        $this->registrations = new RegistrationRepository();
    }

    /**
     * GET /registrations/me
     * Retourne les inscriptions de l'utilisateur connecté avec les données de la séance associée.
     */
    public function getMyRegistrations(): void
    {
        // 1. On s'assure que l'utilisateur est bien connecté (récupération de son ID via JWT)
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        // 2. On demande au Repository de récupérer les inscriptions de cet utilisateur
        $myRegistrations = $this->registrations->findUserRegistrations($userId);

        // 3. On retourne le résultat au format JSON avec un code 200 (Succès)
        http_response_code(200);
        echo json_encode($myRegistrations);
    }

    /**
     * POST /registrations
     * Inscrit l'utilisateur connecté à une séance spécifique.
     */
    public function register(): void
    {
        // 1. Validation de la session utilisateur
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        // 2. Lecture du paramètre 'session_id' envoyé au format JSON par l'application
        $data = json_decode(file_get_contents('php://input'), true);
        $sessionId = $data['session_id'] ?? null;

        // Si l'ID de la séance manque, on renvoie une erreur 422
        if (!$sessionId) {
            http_response_code(422);
            echo json_encode(['error' => 'session_id est requis']);
            return;
        }

        try {
            // 3. On délègue toute la logique complexe d'inscription au Repository.
            // Le repository vérifie si la séance existe, s'il y a de la place,
            // si la limite des 7 jours est respectée, gère la concurrence, etc.
            $this->registrations->registerUser($userId, $sessionId);

            // 4. Si la méthode ci-dessus n'a pas déclenché d'Exception, c'est que l'inscription a réussi.
            http_response_code(201); // 201 = Créé avec succès
            echo json_encode(['message' => 'Inscription confirmée']);
            
        } catch (Exception $e) {
            // S'il y a eu un problème (Ex: Plus de places dispo, doublon, etc.)
            // Le Repository renvoie le code HTTP dans le champ "code" de l'Exception
            $httpCode = $e->getCode() ?: 400; // 400 par défaut si non précisé
            http_response_code($httpCode);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * DELETE /registrations/{sessionId}
     * Désinscrit l'utilisateur connecté d'une séance.
     */
    public function unregister(string $id): void
    {
        $sessionId = $id;

        // 1. Vérification que l'utilisateur est connecté
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        // 2. Le Repository s'occupe de la suppression en base de données
        $this->registrations->unregisterUser($userId, $sessionId);

        // 3. On confirme que la désinscription s'est bien passée
        http_response_code(200);
        echo json_encode(['message' => 'Désinscription effectuée']);
    }
}

