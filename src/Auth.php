<?php
// src/Auth.php
// Helper d'authentification JWT sans namespace

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/Database.php';
function_exists('getallheaders') || require_once __DIR__ . '/helpers.php'; // Au cas où

class Auth
{
    /**
     * Vérifie le JWT dans l'en-tête Authorization.
     * Retourne le payload décodé (array) ou termine la requête avec 401.
     */
    public static function requireAuth(): array
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'Token manquant']);
            exit;
        }

        $jwt = $matches[1];
        $secret = $_ENV['JWT_SECRET'] ?? 'default_secret_change_me';

        try {
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
            $payload = (array) $decoded;

            // Charger les rôles depuis la DB si non présents dans le token
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
            http_response_code(401);
            echo json_encode(['error' => 'Token invalide : ' . $e->getMessage()]);
            exit;
        }
    }

    /**
     * Vérifie que l'utilisateur connecté possède au moins un des rôles spécifiés.
     * Retourne le payload si ok, termine avec 403 sinon.
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
        echo json_encode(['error' => 'Accès refusé — rôle insuffisant']);
        exit;
    }

    /**
     * Tente de récupérer le payload sans bloquer la requête si invalide.
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

    /**
     * Tente de récupérer l'ID utilisateur sans bloquer.
     */
    public static function getUserId(): ?string
    {
        $payload = self::getPayload();
        return $payload['sub'] ?? null;
    }
}
