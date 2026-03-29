<?php
// src/Controllers/SessionController.php
namespace App\Controllers;

use Auth;
use App\Helpers\Sanitizer;
use App\Repositories\SessionRepository;
use App\Services\MailerService;
use Exception;

/**
 * Ce contrôleur gère uniquement la logique HTTP ("Qu'est-ce qu'on renvoie au navigateur ?").
 * Il ne contient AUCUNE requête SQL "brute". Toute la logique de base de données 
 * a été déplacée dans le `SessionRepository` selon le principe de "Séparation des Responsabilités".
 */
class SessionController
{
    private SessionRepository $sessions;

    public function __construct()
    {
        // On instancie le "dépôt" qui va gérer la base de données pour nous.
        $this->sessions = new SessionRepository();
    }

    /**
     * GET /sessions[?status=draft|published|all&include=registrations]
     * Récupère la liste des séances (planning public ou privé).
     */
    public function index(): void
    {
        // 1. On lit les paramètres optionnels envoyés dans l'URL (ex: ?status=all)
        $status = $_GET['status'] ?? 'published';
        $include = $_GET['include'] ?? '';
        $currentUserId = Auth::getUserId();

        // 2. Si on demande les noms des inscrits, il faut obligatoirement être admin/coach (sécurité RGPD)
        if ($include === 'registrations') {
            Auth::requireRole(['admin', 'coach']);
        }

        // 3. On demande au Repository de faire le gros du travail SQL
        $sessions = $this->sessions->findAllWithDetails($status, $include, $currentUserId);

        // 4. On renvoie le résultat formaté en JSON avec un code 200 (Succès)
        http_response_code(200);
        echo json_encode($sessions);
    }

    /**
     * POST /sessions
     * Création d'une ou plusieurs séances.
     */
    public function create(): void
    {
        // 1. Seuls les admins et coachs peuvent créer des séances
        Auth::requireRole(['admin', 'coach']);

        // 2. On récupère les données envoyées par le navigateur au format JSON
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Données invalides']);
            return;
        }

        // 3. Pour gérer la création de masse (batch), on s'assure d'avoir un tableau de séances
        $sessionsToCreate = isset($data[0]) ? $data : [$data];
        $preparedData = [];

        try {
            // 4. On boucle sur chaque séance pour valider qu'il ne manque rien (Sécurité)
            foreach ($sessionsToCreate as $session) {
                // Champs strictement obligatoires
                $required = ['title', 'date', 'start_time', 'end_time'];
                foreach ($required as $field) {
                    if (empty($session[$field])) {
                        throw new Exception("Le champ '$field' est requis pour toutes les séances");
                    }
                }

                // 5. Nettoyage des données (Sanitizing) pour éviter les failles XSS ou injections
                $preparedData[] = [
                    Sanitizer::text($session['title'], 100),
                    Sanitizer::date($session['date']),
                    Sanitizer::time($session['start_time']),
                    Sanitizer::time($session['end_time']),
                    $session['type_id']        ?? null,
                    $session['location_id']    ?? null,
                    $session['max_people']     ?? null,     // Ancien champ 'capacity'
                    $session['min_people']     ?? 1,
                    $session['max_people']     ?? 10,
                    isset($session['min_people_blocking']) ? (int)$session['min_people_blocking'] : 1,
                    isset($session['max_people_blocking']) ? (int)$session['max_people_blocking'] : 1,
                    Sanitizer::text($session['equipment_coach'] ?? '',    200),
                    Sanitizer::text($session['equipment_clients'] ?? '',   200),
                    Sanitizer::text($session['equipment_location'] ?? '', 200),
                    Sanitizer::enum($session['status'] ?? 'published', ['draft','published','cancelled','completed']),
                    Sanitizer::text($session['description'] ?? '', 1000),
                    Auth::getUserId(), // C'est le compte connecté qui est l'auteur
                    !empty($session['limit_registration_7_days']) ? 1 : 0
                ];
            }

            // 6. On délègue la sauvegarde en base de données au Repository (via une Transaction SQL)
            $this->sessions->createMultiple($preparedData);

            // 7. --- NOTIFICATION EMAIL DES NOUVELLES SÉANCES ---
            try {
                // On récupère uniquement les adhérents voulant être notifiés
                $bccEmails = $this->sessions->getNewSessionsSubscribers();

                if (!empty($bccEmails)) {
                    // On délègue l'envoi d'e-mail au MailerService
                    $mailer = new MailerService();
                    $mailer->sendNewSessionsNotification($bccEmails, $sessionsToCreate);
                }
            } catch (Exception $eMail) {
                // On log l'erreur d'email en silence car la séance a quand même été créée en BDD
                error_log("Erreur Mailer (Nouvelles Séances) : " . $eMail->getMessage());
            }

            // 8. Tout s'est bien passé
            http_response_code(201); // 201 = Created
            echo json_encode(['message' => count($sessionsToCreate) . ' séance(s) créée(s)']);
            
        } catch (Exception $e) {
            // S'il y a eu une erreur de type (titre vide, date invalide...)
            http_response_code(422); // 422 = Unprocessable Entity
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * PUT /sessions/{id}
     * Mise à jour des informations d'une séance spécifique.
     */
    public function update(string $id): void
    {
        // 1. Protection des rôles
        Auth::requireRole(['admin', 'coach']);

        // 2. Récupération des données JSON envoyées
        $data = json_decode(file_get_contents('php://input'), true);

        // Map le vieux champ 'capacity' vers le nouveau 'max_people' pour rétrocompatibilité
        if (isset($data['capacity']) && !isset($data['max_people'])) {
            $data['max_people'] = $data['capacity'];
        }

        // 3. Liste des colonnes qu'un utilisateur a le droit de modifier (Sécurité)
        $allowed = [
            'title', 'date', 'start_time', 'end_time', 'type_id', 'location_id', 
            'capacity', 'status', 'description',
            'min_people', 'max_people', 'min_people_blocking', 'max_people_blocking',
            'equipment_coach', 'equipment_clients', 'equipment_location',
            'limit_registration_7_days'
        ];
        
        $updates = [];
        $values  = [];

        // 4. On boucle sur les paramètres envoyés. S'ils sont autorisés, on prépare la màj
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                // On utilise les backticks MySQL au cas où un nom de colonne soit un mot réservé
                $updates[] = "`$field` = ?";
                $values[]  = $data[$field];
            }
        }

        if (empty($updates)) {
            http_response_code(422);
            echo json_encode(['error' => 'Aucun champ valide fourni pour la mise à jour']);
            return;
        }

        // 5. On demande au Repository d'exécuter la requête d'UPDATE
        $this->sessions->update($id, $updates, $values);

        http_response_code(200); // 200 = OK
        echo json_encode(['message' => 'Séance mise à jour avec succès']);
    }

    /**
     * DELETE /sessions/{id}
     * Supprime une séance.
     */
    public function delete(string $id): void
    {
        // 1. Protection
        Auth::requireRole(['admin', 'coach']);

        // 2. Le Repository se chargera de supprimer proprement les inscriptions avant la séance (Cascade)
        $this->sessions->delete($id);

        http_response_code(200);
        echo json_encode(['message' => 'Séance supprimée définitivement']);
    }
}

