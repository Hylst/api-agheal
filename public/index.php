<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/ProfileController.php';
require_once __DIR__ . '/../src/Controllers/SessionController.php';
require_once __DIR__ . '/../src/Controllers/LocationController.php';
require_once __DIR__ . '/../src/Controllers/GroupController.php';
require_once __DIR__ . '/../src/Controllers/ContactController.php';
require_once __DIR__ . '/../src/Controllers/AdminController.php';
require_once __DIR__ . '/../src/Controllers/ClientController.php';
require_once __DIR__ . '/../src/Controllers/SessionTypeController.php';
require_once __DIR__ . '/../src/Controllers/RegistrationController.php';
require_once __DIR__ . '/../src/Controllers/CommunicationController.php';
require_once __DIR__ . '/../src/Controllers/PushController.php';
require_once __DIR__ . '/../src/Controllers/EmailCampaignController.php';

use Dotenv\Dotenv;

// Charger les variables d'environnement
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// ─── CORS ────────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:8080',
    'http://localhost:3000',
    'https://agheal.hylst.fr' // URL de production
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || empty($origin)) {
    header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
} else {
    $appFrontendUrl = getenv('FRONTEND_URL') ?: ($_ENV['FRONTEND_URL'] ?? '');
    if ($appFrontendUrl && rtrim($origin, '/') === rtrim($appFrontendUrl, '/')) {
        header("Access-Control-Allow-Origin: $origin");
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Gérer les requêtes OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Router ──────────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Retirer le préfixe si l'API est dans un sous-dossier (développement local WAMP)
$basePaths = ['/agheal-api/public', '/api/public', '/public'];
foreach ($basePaths as $bp) {
    if (strpos($uri, $bp) === 0) {
        $uri = substr($uri, strlen($bp));
        break;
    }
}
if (empty($uri) || $uri === '') {
    $uri = '/';
}

$method = $_SERVER['REQUEST_METHOD'];

// ─── Définition des routes ───────────────────────────────────────────────────
// Format : 'METHODE /chemin/{param}' => [ControllerClass, 'method', [params_en_ordre]]
$routes = [
    // ── Auth ──────────────────────────────────────────
    'POST /auth/login'              => ['AuthController',         'login'],
    'POST /auth/signup'             => ['AuthController',         'signup'],
    'POST /auth/reset-password'     => ['AuthController',         'resetPassword'],

    // ── Profiles ───────────────────────────────────────
    'GET /profiles/me'              => ['ProfileController',      'me'],
    'PUT /profiles/me/notifications'=> ['ProfileController',      'updateNotifications'],
    'GET /profiles/{id}'            => ['ProfileController',      'show'],
    'PUT /profiles/{id}'            => ['ProfileController',      'update'],
    'GET /profiles/{id}/groups'     => ['ProfileController',      'getGroups'],

    // ── Sessions ───────────────────────────────────────
    'GET /sessions'                 => ['SessionController',      'index'],
    'POST /sessions'                => ['SessionController',      'create'],
    'GET /sessions/{id}'            => ['SessionController',      'show'],
    'PUT /sessions/{id}'            => ['SessionController',      'update'],
    'DELETE /sessions/{id}'         => ['SessionController',      'delete'],

    // ── Registrations ──────────────────────────────────
    'GET /registrations/me'         => ['RegistrationController', 'getMyRegistrations'],
    'POST /registrations'           => ['RegistrationController', 'register'],
    'DELETE /registrations/{id}'    => ['RegistrationController', 'unregister'],

    // ── Session Types (Activities) ─────────────────────
    'GET /session-types'            => ['SessionTypeController',  'index'],
    'POST /session-types'           => ['SessionTypeController',  'create'],
    'PUT /session-types/{id}'       => ['SessionTypeController',  'update'],
    'DELETE /session-types/{id}'    => ['SessionTypeController',  'delete'],

    // ── Locations ──────────────────────────────────────
    'GET /locations'                => ['LocationController',     'index'],
    'POST /locations'               => ['LocationController',     'create'],
    'PUT /locations/{id}'           => ['LocationController',     'update'],
    'DELETE /locations/{id}'        => ['LocationController',     'delete'],

    // ── Groups ─────────────────────────────────────────
    'GET /groups'                   => ['GroupController',        'index'],
    'POST /groups'                  => ['GroupController',        'create'],
    'PUT /groups/{id}'              => ['GroupController',        'update'],
    'DELETE /groups/{id}'           => ['GroupController',        'delete'],

    // ── Admin ──────────────────────────────────────────
    'GET /admin/users'                          => ['AdminController', 'getUsers'],
    'PUT /admin/users/{id}/status'              => ['AdminController', 'updateStatus'],
    'POST /admin/users/{id}/roles'              => ['AdminController', 'addRole'],
    'DELETE /admin/users/{id}/roles/{role}'     => ['AdminController', 'removeRole'],

    // ── Clients (coach view) ───────────────────────────
    'GET /clients'                  => ['ClientController',       'index'],
    'PUT /clients/{id}'             => ['ClientController',       'update'],
    'PUT /clients/{id}/groups'      => ['ClientController',       'setGroups'],

    // ── Communications ─────────────────────────────────
    'GET /communications'           => ['CommunicationController', 'index'],
    'GET /communications/my'        => ['CommunicationController', 'getMy'],
    'POST /communications'          => ['CommunicationController', 'save'],
    'PUT /communications/{id}'      => ['CommunicationController', 'update'],
    'DELETE /communications/{id}'   => ['CommunicationController', 'delete'],

    // ── Push Notifications ─────────────────────────────
    'POST /push/subscribe'          => ['PushController', 'subscribe'],
    'POST /push/unsubscribe'        => ['PushController', 'unsubscribe'],

    // ── Email Campaigns ────────────────────────────────
    'GET /email-campaigns'          => ['EmailCampaignController', 'index'],
    'POST /email-campaigns'         => ['EmailCampaignController', 'create'],
    'DELETE /email-campaigns/{id}'  => ['EmailCampaignController', 'delete'],

    // ── Contact ────────────────────────────────────────
    'POST /contact'                 => ['ContactController',      'send'],
];

// ─── Dispatch ────────────────────────────────────────────────────────────────
$handler = null;
$routeParams = [];

foreach ($routes as $routeKey => $controllerAction) {
    [$routeMethod, $routePath] = explode(' ', $routeKey, 2);

    if ($method !== $routeMethod) {
        continue;
    }

    // Construire le pattern regex depuis le template de route
    $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $routePath);
    $pattern = "@^" . $pattern . "$@D";

    if (preg_match($pattern, $uri, $matches)) {
        $handler = $controllerAction;
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $routeParams[$key] = $value;
            }
        }
        break;
    }
}

if (!$handler) {
    http_response_code(404);
    echo json_encode(['error' => "Route introuvable : $method $uri"]);
    exit;
}

// ─── Invocation du contrôleur ────────────────────────────────────────────────
[$controllerClass, $action] = $handler;

try {
    if (!class_exists($controllerClass)) {
        throw new RuntimeException("Contrôleur introuvable : $controllerClass");
    }

    $controller = new $controllerClass();

    if (!method_exists($controller, $action)) {
        throw new RuntimeException("Méthode introuvable : $controllerClass::$action");
    }

    // Passer les paramètres de route en arguments positionnels
    // On inspecte la signature de la méthode pour injecter dans l'ordre
    $ref = new ReflectionMethod($controller, $action);
    $params = $ref->getParameters();
    $args = [];
    foreach ($params as $param) {
        $name = $param->getName();
        if (isset($routeParams[$name])) {
            // Cast en int si le paramètre est typé int
            $type = $param->getType();
            $args[] = ($type instanceof ReflectionNamedType && $type->getName() === 'int')
                ? (int) $routeParams[$name]
                : $routeParams[$name];
        } elseif ($param->isOptional()) {
            $args[] = $param->getDefaultValue();
        }
    }

    $controller->$action(...$args);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
}
