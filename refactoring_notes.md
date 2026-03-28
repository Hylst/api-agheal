# AGHeal – Refactoring & Séparation des Responsabilités

## 1. État actuel (résumé rapide)

| Couche | Structure actuelle | Note |
|---|---|---|
| Routeur | Table `$routes[]` dans `index.php` | ✅ Clean |
| Controllers | 18 contrôleurs, logique + validation mélangées | ⚠️ À affiner |
| Services | `MailerService`, `PushService`, `EmailService` | ✅ Bien isolés |
| Helpers | `Sanitizer.php` (nouveau) | ✅ |
| Middleware | `AuthMiddleware.php` présent mais non utilisé par le routeur | ⚠️ Peut être intégré |
| Repositories | **Absents** — les contrôleurs accèdent directement à `Database` | ⚠️ Refactoring possible |

---

## 2. Refactorisations recommandées (par priorité)

### 🔴 Priorité haute

#### A. Supprimer l'exposition des erreurs en production (`index.php`)

Actuellement, en cas d'exception, `index.php` retourne le **fichier source et la ligne** :
```php
// ❌ Actuellement — expose le code source
echo json_encode([
    'error' => $e->getMessage(),
    'file'  => basename($e->getFile()),   // ← fuite d'info
    'line'  => $e->getLine(),             // ← fuite d'info
]);
```
**Fix immédiat** : Masquer ces détails en production via la variable `APP_ENV` :
```php
// ✅ Recommandé
$isDebug = (getenv('APP_ENV') ?: 'production') === 'development';
echo json_encode([
    'error' => $isDebug ? $e->getMessage() : 'Erreur interne du serveur',
    'file'  => $isDebug ? basename($e->getFile()) : null,
    'line'  => $isDebug ? $e->getLine() : null,
]);
```

#### B. Utiliser `AuthMiddleware.php` (déjà créé, mais non branché)

Le fichier `src/Middleware/AuthMiddleware.php` existe. Actuellement chaque contrôleur appelle `Auth::requireRole()` directement. Ce n'est pas un problème majeur mais une duplication. À terme, le middleware peut être invoqué dans le routeur pour les routes protégées.

---

### 🟡 Priorité moyenne

#### C. Pattern Repository (couche d'accès aux données)

Actuellement les contrôleurs font directement `Database::getInstance()->query(...)`. Si la base change (ORM, tests unitaires), toute la logique doit être réécrite.

**Architecture recommandée :**
```
Controllers/  → Valident l'input, appellent les Repositories
Repositories/ → Toute la logique SQL (UserRepository, SessionRepository...)
Services/     → Logique métier complexe cross-entités
Helpers/      → Sanitizer, formateurs, utilitaires
```

Exemple minimal :
```php
// src/Repositories/SessionRepository.php
class SessionRepository {
    public function findUpcoming(): array { /* SQL ici */ }
    public function create(array $data): void { /* SQL ici */ }
}

// src/Controllers/SessionController.php
class SessionController {
    private SessionRepository $repo;
    public function __construct() {
        $this->repo = new SessionRepository();
    }
    public function index(): void {
        $sessions = $this->repo->findUpcoming();
        echo json_encode($sessions);
    }
}
```

> **À faire progressivement** : commencer par `SessionRepository` et `PaymentRepository` qui ont le plus de requêtes SQL.

#### D. Étendre `Sanitizer` dans tous les contrôleurs qui admettent du texte libre

Les contrôleurs suivants admettent des champs texte libre qu'il serait bon de passer par `Sanitizer::text()` :

| Contrôleur | Champs à sanitizer |
|---|---|
| `SessionController` | `title`, `description`, `equipment_*` |
| `PaymentController` | `comment` |
| `CommunicationController` | `content`, `subject` |
| `ContactController` | `message`, `name` |
| `ProfileController` | `phone`, `bio`, `address` |

---

### 🟢 Priorité basse

#### E. Autoloading PSR-4 (Composer)

Actuellement, `index.php` charge manuellement 18 `require_once`. Avec un `composer.json` PSR-4 :
```json
"autoload": {
    "psr-4": {
        "App\\Controllers\\": "src/Controllers/",
        "App\\Services\\":    "src/Services/",
        "App\\Helpers\\":     "src/Helpers/",
        "App\\Repositories\\": "src/Repositories/"
    }
}
```
→ `composer dump-autoload`, puis `index.php` n'a plus besoin des `require_once`.

#### F. Validation centralisée (Request Objects)

À terme : créer des objets `CreateSessionRequest`, `CreatePaymentRequest` etc. qui encapsulent la validation et le sanitizing, pour que les contrôleurs ne fassent que `$request->validate()`.

---

## 3. Traefik Router Name – Comment le trouver dans Coolify

### D'après votre screenshot (Resources)

Vous avez deux applications visibles :
- `hylst/agheal-front:main-bcogw4wowkw0kw8wkgs40wos` → `https://agheal.hylst.fr`
- `hylst/api-agheal:main-jcokcck0o0ockco0g8s0cgok` → `https://api.agheal.hylst.fr` ← **c'est celle-ci**

### Étapes pour trouver le nom du router

1. **Cliquez** sur la carte `hylst/api-agheal:main-jcokcck0o0ockco0g8s0cgok`
2. Onglet **Configuration** → section **Container Labels** (tout en bas de la page)
3. Cherchez une ligne contenant `traefik.http.routers.` — elle ressemble à :
   ```
   traefik.http.routers.https-0-jcokcck0o0ockco0g8s0cgok.rule=Host(`api.agheal.hylst.fr`)
   ```
4. Le nom du router est la partie après `traefik.http.routers.` et avant `.rule` → ex: `https-0-jcokcck0o0ockco0g8s0cgok`

### Labels à ajouter (en utilisant votre vrai nom de router)

```
# Remplacez ROUTER_NAME par la valeur trouvée à l'étape 4
traefik.http.middlewares.agheal-headers.headers.frameDeny=true
traefik.http.middlewares.agheal-headers.headers.contentTypeNosniff=true
traefik.http.middlewares.agheal-headers.headers.browserXssFilter=true
traefik.http.middlewares.agheal-headers.headers.referrerPolicy=strict-origin-when-cross-origin
traefik.http.middlewares.agheal-headers.headers.stsSeconds=31536000
traefik.http.middlewares.agheal-headers.headers.stsIncludeSubdomains=true
traefik.http.middlewares.agheal-headers.headers.forceSTSHeader=true
traefik.http.middlewares.agheal-ratelimit.rateLimit.average=100
traefik.http.middlewares.agheal-ratelimit.rateLimit.burst=50
traefik.http.middlewares.agheal-ratelimit.rateLimit.period=10s
traefik.http.routers.ROUTER_NAME.middlewares=agheal-headers@docker,agheal-ratelimit@docker
```

---

## 4. CORS – Où et comment (déjà en place + durci)

Le CORS est géré dans `public/index.php` (déjà le bon endroit). Il vient d'être durci :

| Avant | Après |
|---|---|
| `empty($origin)` → renvoie `*` | `empty($origin)` → pas de header CORS (no-op) |
| Origin inconnue → rien | Origin inconnue → HTTP 403 explicite |
| `FRONTEND_URL` vérifié en doublon | Intégré dans `$allowedOrigins` dès le départ |
| `OPTIONS` → 200 | `OPTIONS` → 204 (standard RFC correct) |

**Vous n'avez rien d'autre à faire** pour le CORS : il est géré applicativement avant Traefik. L'en-tête `Vary: Origin` a aussi été ajouté pour les CDN/proxys.
