<?php
// src/Auth.php
//
// Helper d'auth JWT. Vitrine de la securite cote backend.
// 3 methodes statiques :
//   requireAuth()   : Bloque si pas de JWT valide (HTTP 401). Renvoie le payload.
//   requireRole()   : Bloque si role insuffisant (HTTP 403). Pour les routes protegees.
//   getPayload()    : Tente sans bloquer (utile si endpoint a comportement different
//                     selon user connecte / pas connecte).
//
// /!\ JWT_SECRET dans .env, JAMAIS en dur dans le code. Le default ci-dessous
// ('default_secret_change_me') est un garde-fou de dev, refuse en prod par
// le pipeline normal.

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/Database.php';
function_exists('getallheaders') || require_once __DIR__ . '/helpers.php'; // au cas ou (Apache sans mod_rewrite)

class Auth
{
    /**
     * Verifie le JWT dans le header Authorization. Termine la requete avec 401
     * si KO, sinon renvoie le payload decode (array).
     *
     * Particularite : si le token ne contient pas 'roles', on les recharge
     * depuis la BDD. Ca evite qu'un user dont on a change les roles continue
     * d'utiliser un vieux token avec les anciens. Trade-off : 1 requete BDD
     * de plus par requete HTTP authentifiee, mais ca evite les bypass.
     */
    public static function requireAuth(): array
    {
        $headers = getallheaders();
        // Apache mange parfois la casse du header. On check les 2 variantes.
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'Token manquant']);
            exit;
        }

        $jwt = $matches[1];
        $secret = $_ENV['JWT_SECRET'] ?? 'default_secret_change_me';

        try {
            // HS256 explicite : on n'accepte QUE cet algo. Bloque l'attaque
            // "alg=none" qui consiste a passer un JWT non signe (CVE-2025-45769
            // sur les vieilles versions de firebase/php-jwt).
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
            $payload = (array) $decoded;

            // Recharge des roles depuis la BDD si absents du token (cf entete).
            if (!isset($payload['roles'])) {
                $db = \Database::getInstance();
                $stmt = $db->query(
                    "SELECT role FROM user_roles WHERE user_id = ?",
                    [$payload['sub']]
                );
                $payload['roles'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            }

            return $payload;
        } catch (\Exception $e) {
            // Token expire, signature invalide, mal forme... tous les cas
            // donnent 401 avec un msg generique. On evite de leak le detail
            // d'erreur en prod (ATTR_EMULATE_PREPARES=false en BDD a une logique
            // similaire : la securite par defense en profondeur).
            http_response_code(401);
            echo json_encode(['error' => 'Token invalide : ' . $e->getMessage()]);
            exit;
        }
    }

    /**
     * Verifie qu'un des roles autorises est present chez l'user.
     * Renvoie le payload si OK, 403 sinon.
     *
     * Utilise un OR logique : passer ['coach', 'admin'] = "coach OU admin".
     * Pour un AND (cumul obligatoire), il faudrait une autre methode mais
     * on n'en a pas le besoin metier actuellement.
     */
    public static function requireRole(array $allowedRoles): array
    {
        $payload = self::requireAuth();
        $userRoles = $payload['roles'] ?? [];

        foreach ($allowedRoles as $role) {
            if (in_array($role, $userRoles)) {
                return $payload;
            }
        }

        http_response_code(403);
        echo json_encode(['error' => 'Acces refuse, role insuffisant']);
        exit;
    }

    /**
     * Tente de recup le payload sans bloquer la requete si JWT absent/invalide.
     * Utile pour les endpoints publics qui adaptent leur reponse selon que
     * l'user est connecte ou pas (ex : page d'accueil qui affiche ou non
     * les liens du dashboard).
     */
    public static function getPayload(): ?array
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        $jwt = $matches[1];
        $secret = $_ENV['JWT_SECRET'] ?? 'default_secret_change_me';

        try {
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Recup juste l'user_id sans bloquer. Raccourci. */
    public static function getUserId(): ?string
    {
        $payload = self::getPayload();
        return $payload['sub'] ?? null;
    }
}
