<?php
// src/Controllers/ProfileController.php
namespace App\Controllers;

use Auth;
use App\Helpers\Sanitizer;
use App\Repositories\ProfileRepository;

/**
 * Ce contrôleur gère toutes les requêtes HTTP liées aux profils utilisateurs.
 * Conformément au principe du "Repository", il ne fait aucune requête SQL lui-même.
 */
class ProfileController
{
    private ProfileRepository $profiles;

    public function __construct()
    {
        // Instanciation de notre accès aux données de profil
        $this->profiles = new ProfileRepository();
    }

    /**
     * GET /profiles/me
     * Retourne les informations complètes du profil de l'utilisateur connecté.
     */
    public function me(): void
    {
        // 1. On vérifie que l'utilisateur est connecté et on récupère son ID
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        // 2. Le Repository gère les jointures complexes (avec l'email et les rôles)
        $userData = $this->profiles->findWithDetails($userId);

        // 3. Si aucun profil n'a été créé pour ce compte, on renvoie une 404
        if (!$userData) {
            http_response_code(404);
            echo json_encode(['error' => 'Profil introuvable']);
            return;
        }

        // 4. On renvoie les données (le frontend s'y attend sous la forme { user: {...} })
        http_response_code(200);
        echo json_encode(['user' => $userData]);
    }

    /**
     * GET /profiles/{id}
     * Retourne les informations publiques liées au profil d'un autre utilisateur.
     */
    public function show(string $id): void
    {
        Auth::requireAuth();

        // On récupère juste les détails de base
        $profile = $this->profiles->findById($id);

        if (!$profile) {
            http_response_code(404);
            echo json_encode(['error' => 'Profil introuvable']);
            return;
        }

        http_response_code(200);
        echo json_encode($profile);
    }

    /**
     * PUT /profiles/{id}
     * Met à jour les informations du profil utilisateur
     */
    public function update(string $id): void
    {
        $currentUser = Auth::requireAuth();

        // 1. Règle de sécurité : Un utilisateur ne peut modifier que TON profil
        // SAUF s'il est Administrateur (il peut modifier le profil de n'importe qui)
        if ($currentUser['sub'] !== $id && !in_array('admin', $currentUser['roles'] ?? [])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        // 2. On récupère les informations envoyées (+ décodage du JSON)
        $data = json_decode(file_get_contents('php://input'), true);

        // 3. On dresse une "Liste blanche" de ce qu'il a le droit de modifier
        $allowed = [
            'first_name', 'last_name', 'phone', 'organization',
            'remarks_health', 'additional_info', 'age', 'avatar_base64',
        ];

        $fieldsToUpdate = [];

        // 4. Boucle pour nettoyer et préparer les champs à mettre à jour
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                // Pour éviter que des scripts JS ou du code HTML malveillant soit inséré en base (Faille XSS)
                // on "Sanitize" (nettoie) le texte
                $textFields = ['first_name','last_name','phone','organization','remarks_health','additional_info'];
                
                $value = in_array($field, $textFields, true)
                    ? Sanitizer::text($data[$field], 255)
                    : $data[$field];
                
                $fieldsToUpdate[$field] = $value;
            }
        }

        // 5. Rien d'utile n'a été envoyé ?
        if (empty($fieldsToUpdate)) {
            http_response_code(422);
            echo json_encode(['error' => 'Aucun champ valide fourni']);
            return;
        }

        // 6. On exécute la mise à jour via le Repository
        $this->profiles->update($id, $fieldsToUpdate);

        http_response_code(200); // 200 = OK
        echo json_encode(['message' => 'Profil mis à jour']);
    }

    /**
     * PUT /profiles/me/notifications
     * Mise à jour spécifique pour cocher/décocher des notifications
     */
    public function updateNotifications(): void
    {
        // On récupère uniquement l'UID de la personne qui fait la requête
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        // On récupère ses préférences (true / false paramétré depuis le fontend)
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            http_response_code(422);
            echo json_encode(['error' => 'Format invalide']);
            return;
        }

        // Le repository va filtrer et s'assurer que seuls les bons paramètres sont modifiés
        $this->profiles->updateNotifications($userId, $data);

        http_response_code(200);
        echo json_encode(['message' => 'Préférences de notification mises à jour']);
    }

    /**
     * GET /profiles/{id}/groups
     * Retourne la liste des groupes (catégories) auxquels cet utilisateur appartient
     */
    public function getGroups(string $id): void
    {
        Auth::requireAuth();

        $groups = $this->profiles->getGroups($id);

        http_response_code(200);
        echo json_encode($groups);
    }
}

