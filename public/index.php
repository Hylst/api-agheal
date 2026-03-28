<?php
// public/index.php
// Controllers resolved automatically via Composer PSR-4 autoload (config: composer.json)

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use Dotenv\Dotenv;

// Charger les variables d'environnement
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// ─── CORS ────────────────────────────────────────────────────────────────────
// ⚠ Ne jamais utiliser '*' en production avec `credentials: true`.
// La liste des origines autorisées est complète et explicite.
$allowedOrigins = array_filter([
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:8080',
    'http://localhost:3000',
    'https://agheal.hylst.fr',
    rtrim(getenv('FRONTEND_URL') ?: ($_ENV['FRONTEND_URL'] ?? ''), '/'),
]);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && in_array(rtrim($origin, '/'), $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
} elseif ($origin === '') {
    // Appel sans origin (Postman, cron, server-to-server) : on n'envoie pas de header CORS.
    // Les navigateurs envoient TOUJOURS Origin, donc pas de risque de fuite XSS.
    true; // no-op conscient
} else {
    // Origine inconnue → refus explicite.
    http_response_code(403);
    echo json_encode(['error' => 'Origine non autorisée']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 3600'); // Cache preflight 1h

// Gérer les requêtes OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
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
    'POST /auth/login'              => ['App\Controllers\AuthController',       'login'],
    'POST /auth/signup'             => ['App\Controllers\AuthController',       'signup'],
    'POST /auth/reset-password'     => ['App\Controllers\AuthController',       'resetPassword'],
    'GET /auth/google'              => ['App\Controllers\GoogleAuthController', 'redirect'],
    'GET /auth/google/callback'     => ['App\Controllers\GoogleAuthController', 'callback'],

    // ── Profiles ───────────────────────────────────────
    'GET /profiles/me'               => ['App\Controllers\ProfileController',      'me'],
    'PUT /profiles/me/notifications' => ['App\Controllers\ProfileController',      'updateNotifications'],
    'GET /profiles/{id}'             => ['App\Controllers\ProfileController',      'show'],
    'PUT /profiles/{id}'             => ['App\Controllers\ProfileController',      'update'],
    'GET /profiles/{id}/groups'      => ['App\Controllers\ProfileController',      'getGroups'],

    // ── Sessions ───────────────────────────────────────
    'GET /sessions'                 => ['App\Controllers\SessionController',      'index'],
    'POST /sessions'                => ['App\Controllers\SessionController',      'create'],
    'GET /sessions/{id}'            => ['App\Controllers\SessionController',      'show'],
    'PUT /sessions/{id}'            => ['App\Controllers\SessionController',      'update'],

    // ── Attendance ─────────────────────────────────────
    'GET /sessions/{sessionId}/attendance'            => ['App\Controllers\AttendanceController', 'getAttendance'],
    'PUT /sessions/{sessionId}/attendance'            => ['App\Controllers\AttendanceController', 'updateAttendance'],
    'GET /sessions/{sessionId}/attendance/candidates' => ['App\Controllers\AttendanceController', 'getCandidates'],
    'DELETE /sessions/{id}'                           => ['App\Controllers\SessionController',    'delete'],

    // ── Stats & Logs ───────────────────────────────────
    'GET /stats/overview'                    => ['App\Controllers\StatsController', 'overview'],
    'GET /stats/sessions'                    => ['App\Controllers\StatsController', 'sessionHistory'],
    'GET /stats/sessions/{sessionId}/detail' => ['App\Controllers\StatsController', 'sessionDetail'],
    'GET /stats/members'                     => ['App\Controllers\StatsController', 'memberStats'],
    'GET /stats/payments'                    => ['App\Controllers\StatsController', 'paymentStats'],
    'GET /stats/attendance'                  => ['App\Controllers\StatsController', 'attendanceStats'],
    'GET /stats/logs'                        => ['App\Controllers\StatsController', 'getLogs'],
    'GET /stats/logs/{logId}/download'       => ['App\Controllers\StatsController', 'downloadLog'],
    'GET /stats/logs/export-csv'             => ['App\Controllers\StatsController', 'exportSessionsCsv'],

    // ── Registrations ──────────────────────────────────
    'GET /registrations/me'         => ['App\Controllers\RegistrationController', 'getMyRegistrations'],
    'POST /registrations'           => ['App\Controllers\RegistrationController', 'register'],
    'DELETE /registrations/{id}'    => ['App\Controllers\RegistrationController', 'unregister'],

    // ── Session Types (Activities) ─────────────────────
    'GET /session-types'            => ['App\Controllers\SessionTypeController',  'index'],
    'POST /session-types'           => ['App\Controllers\SessionTypeController',  'create'],
    'PUT /session-types/{id}'       => ['App\Controllers\SessionTypeController',  'update'],
    'DELETE /session-types/{id}'    => ['App\Controllers\SessionTypeController',  'delete'],

    // ── Locations ──────────────────────────────────────
    'GET /locations'                => ['App\Controllers\LocationController',     'index'],
    'POST /locations'               => ['App\Controllers\LocationController',     'create'],
    'PUT /locations/{id}'           => ['App\Controllers\LocationController',     'update'],
    'DELETE /locations/{id}'        => ['App\Controllers\LocationController',     'delete'],

    // ── Groups ─────────────────────────────────────────
    'GET /groups'                   => ['App\Controllers\GroupController',        'index'],
    'POST /groups'                  => ['App\Controllers\GroupController',        'create'],
    'PUT /groups/{id}'              => ['App\Controllers\GroupController',        'update'],
    'DELETE /groups/{id}'           => ['App\Controllers\GroupController',        'delete'],

    // ── Admin ──────────────────────────────────────────
    'GET /admin/users'                      => ['App\Controllers\AdminController', 'getUsers'],
    'GET /admin/coaches'                    => ['App\Controllers\AdminController', 'getCoaches'],
    'PUT /admin/users/{id}/status'          => ['App\Controllers\AdminController', 'updateStatus'],
    'POST /admin/users/{id}/roles'          => ['App\Controllers\AdminController', 'addRole'],
    'DELETE /admin/users/{id}/roles/{role}' => ['App\Controllers\AdminController', 'removeRole'],

    // ── Clients (coach view) ───────────────────────────
    'GET /clients'                  => ['App\Controllers\ClientController',       'index'],
    'PUT /clients/{id}'             => ['App\Controllers\ClientController',       'update'],
    'PUT /clients/{id}/groups'      => ['App\Controllers\ClientController',       'setGroups'],

    // ── Communications ─────────────────────────────────
    'GET /communications'           => ['App\Controllers\CommunicationController', 'index'],
    'GET /communications/my'        => ['App\Controllers\CommunicationController', 'getMy'],
    'POST /communications'          => ['App\Controllers\CommunicationController', 'save'],
    'PUT /communications/{id}'      => ['App\Controllers\CommunicationController', 'update'],
    'DELETE /communications/{id}'   => ['App\Controllers\CommunicationController', 'delete'],

    // ── Push Notifications ─────────────────────────────
    'POST /push/subscribe'          => ['App\Controllers\PushController', 'subscribe'],
    'POST /push/unsubscribe'        => ['App\Controllers\PushController', 'unsubscribe'],

    // ── Email Campaigns ────────────────────────────────
    'GET /email-campaigns'          => ['App\Controllers\EmailCampaignController', 'index'],
    'POST /email-campaigns'         => ['App\Controllers\EmailCampaignController', 'create'],
    'DELETE /email-campaigns/{id}'  => ['App\Controllers\EmailCampaignController', 'delete'],

    // ── History ────────────────────────────────────────
    'GET /history'                  => ['App\Controllers\HistoryController',      'index'],

    // ── Payments ───────────────────────────────────────
    'GET /payments'                 => ['App\Controllers\PaymentController',      'index'],
    'GET /payments/summary'         => ['App\Controllers\PaymentController',      'summary'],
    'POST /payments'                => ['App\Controllers\PaymentController',      'create'],
    'DELETE /payments/{id}'         => ['App\Controllers\PaymentController',      'delete'],

    // ── Contact ────────────────────────────────────────
    'POST /contact'                 => ['App\Controllers\ContactController',      'send'],
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
    $isDebug = (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production')) === 'development';
    http_response_code(500);
    echo json_encode([
        'error' => $isDebug ? $e->getMessage() : 'Erreur interne du serveur',
        'file'  => $isDebug ? basename($e->getFile()) : null,
        'line'  => $isDebug ? $e->getLine() : null,
    ]);
}
