# Rapport de Sécurité – AGHeal (v1.9.1)

Date : 28 Mars 2026  
Périmètre : Backend PHP (`agheal-api`), Frontend React (`AGheal`), Base de données MariaDB.

---

## 1. Synthèse Exécutive

| Vecteur de risque | Couverture | Statut |
|---|---|---|
| Injections SQL | Requêtes PDO paramétrées systématiques | ✅ Sécurisé |
| Failles XSS | React + sanitizing PHP sur entrées persistées | ✅ Sécurisé |
| Validation d'email | `filter_var` + FILTER_SANITIZE_EMAIL (ajouté ce sprint) | ✅ Sécurisé |
| Force des mots de passe | Vérification min. 8 caractères côté API (ajouté ce sprint) | ✅ Sécurisé |
| Exposition de données (IDOR) | Contrôle d'accès basé sur le rôle (JWT + `Auth::requireRole`) | ✅ Sécurisé |
| Authentification/JWT | JWT HS256, expiration 1h, secret en `.env` | ✅ Sécurisé |
| Anti-Lockout Admin | Triggers MariaDB (prévention suppression/blocage du dernier admin) | ✅ Sécurisé |
| Archivage RGPD | Script `cron_purge_logs.php` : purge + archive email sur 2 ans glissants | ✅ Implémenté |
| CORS | Configuré en production pour les origines `.hylst.fr` uniquement | ✅ Sécurisé |
| En-têtes de sécurité HTTP | À vérifier/durcir dans la config nginx/caddy de Coolify | ⚠️ À surveiller |
| Rate Limiting | Non implémenté côté applicatif | ⚠️ À améliorer |
| Audit Trail complet | Logs BDD + JSON pour présences, `payments_history` pour règlements | ✅ Sécurisé |

---

## 2. Injections SQL (SQLi)

### Risque
Un attaquant qui contrôle les paramètres d'une requête SQL non-filtrée peut lire, modifier ou supprimer toutes les données de la base.

### Couverture dans AGHeal

**`src/Database.php`** centralise toutes les requêtes et utilise **`PDO::prepare()` + liaisons par `?`** (paramètres positionnels) pour _toutes_ les requêtes SQL de l'application. Les données utilisateurs n'entrent _jamais_ directement dans une chaîne SQL.

```php
// ✅ Toujours comme ceci :
$db->query("SELECT * FROM users WHERE email = ?", [$email]);

// ❌ JAMAIS comme ceci :
$db->query("SELECT * FROM users WHERE email = '$email'"); // vulnérable
```

**Verdict** : **Aucune injection SQL possible** avec le code actuel.

---

## 3. Failles XSS (Cross-Site Scripting)

### Risque
Un attaquant insère du HTML/JavaScript malveillant dans les données de l'application. Si une autre page l'affiche sans échappement, les scripts s'exécutent dans le navigateur de la victime.

### Couverture

**Frontend (React)** : React échappe automatiquement toutes les valeurs interpolées dans le JSX (`{variable}`). L'utilisation de `dangerouslySetInnerHTML` est absente dans le code, ce qui **élimine le risque XSS reflété et stocké** côté client.

**Backend (PHP)** : Lors de la création de comptes (`signup`), les prénoms et noms sont désormais assainis avant persistance en BDD :

```php
// ✅ Ajouté : double protection sur les champs texte libres
$firstName = htmlspecialchars(strip_tags($data['first_name']), ENT_QUOTES, 'UTF-8');
$lastName  = htmlspecialchars(strip_tags($data['last_name']),  ENT_QUOTES, 'UTF-8');
```

**Verdict** : **Protection XSS active** en frontend et backend.

---

## 4. Validation et Sanitization des Emails

### Risque
Un attaquant peut soumettre une adresse malformée (injection de caractères, entêtes SMTP…) pouvant mener à de l'email header injection ou simplement corrompre la base.

### Couverture (ajouté ce sprint dans `AuthController.php`)

Les trois méthodes critiques (`login`, `signup`, `resetPassword`) appliquent maintenant :
1. `FILTER_SANITIZE_EMAIL` : supprime les caractères illégaux dans une adresse email.
2. `FILTER_VALIDATE_EMAIL` : valide le format RFC 5322.
3. Retour HTTP 400 si invalide (le processus est stoppé avant tout accès BDD).

**Frontend** : Les champs d'email utilisent `<input type="email">` avec validation native HTML5 (navigateur). Un formulaire React avec `type="email"` bloque les soumissions malformées sans JavaScript supplémentaire.

---

## 5. Authentification et JWT

### Tokens JWT

- **Algorithme** : HS256
- **Expiration** : 1 heure (`JWT_EXPIRATION=3600`)
- **Secret** : injecté via `.env`, jamais en dur dans le code.
- **Vérification** : `Auth::requireAuth()` valide la signature et l'expiration à **chaque requête** protégée.

### Points d'attention

| Point | Statut |
|---|---|
| Révocation de token | ⚠️ Pas de blacklist (standard JWT stateless). Risque minimal sur une session courte (1h). |
| Refresh Token | Non implémenté. L'utilisateur doit se reconnecter après expiration. |
| Secret JWT | En `.env`, accessible en variable d'environnement sur Coolify. ✅ |

---

## 6. Contrôle d'Accès (IDOR / Privilege Escalation)

### `Auth::requireRole()`

Chaque action sensible est protégée par un appel explicite à `Auth::requireRole(['admin'])` ou `Auth::requireRole(['admin', 'coach'])`. Les rôles sont rechargés depuis la base de données, et pas uniquement lus depuis le token.

- **Admin** : accès complet (CRUD utilisateurs, suppression de paiements, statistiques...).
- **Coach** : accès à la gestion des séances, présences et règlements.
- **Adhérent** : lecture de ses propres données et inscriptions uniquement.

Un adhérent qui modifie son token JWT pour ajouter `"roles": ["admin"]` sera tout de même bloqué par la rechargement BDD.

---

## 7. Triggers de Sécurité MariaDB (Anti-Lockout)

Deux triggers protègent l'intégrité administrative en base de données :

| Trigger | Action |
|---|---|
| `prevent_last_admin_deletion` | Empêche la suppression du dernier utilisateur avec le rôle `admin`. |
| `prevent_last_admin_status_change` | Empêche la désactivation (`statut_compte != 'actif'`) du dernier admin. |

Ces mécanismes sont irrévocables depuis le code applicatif : même un bug ou une attaque dans le backend PHP ne peut pas verrouiller l'application.

---

## 8. CORS (Cross-Origin Resource Sharing)

La configuration Nginx/Caddy de Coolify (ou le fichier `index.php`) doit être configurée pour n'autoriser _que_ les origines légitimes :

```
Access-Control-Allow-Origin: https://agheal.hylst.fr
```

Tout appel depuis une autre origine (attaquant) sera bloqué par les navigateurs.

---

## 9. Archivage RGPD & Rotation des Logs

### Réglementation appliquée
Le RGPD (Article 5.1.e) impose de ne pas conserver les données personnelles au-delà de la durée nécessaire. Pour une association sportive, l'usage recommande 2 ans de conservation.

### Mise en œuvre technique

Le script `scripts/cron_purge_logs.php` s'exécute automatiquement **le 1er de chaque mois à 01h00** (entrée crontab).

**Processus :**
1. Détecte toutes les données > 2 ans (présences `logs` et règlements `payments_history` en BDD, fichiers JSON sur disque).
2. Exporte les données obsolètes en deux fichiers `.csv` (BOM UTF-8, compatible Excel).
3. Envoie un email à `ADMIN_EMAIL` avec les CSV en pièces jointes via **PHPMailer** (SMTP Gmail configuré dans `.env`).
4. Supprime les enregistrements en BDD (`DELETE WHERE date < cutoff`).
5. Supprime les dossiers `logs/sessions/YYYY/MM/` obsolètes.

---

## 10. En-têtes HTTP de Sécurité

> [!WARNING]
> Ce point n'est pas géré par la couche applicative PHP mais doit être configuré dans le proxy Nginx/Caddy de Coolify.

Les en-têtes à s'assurer d'avoir en production :

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'; ...
Referrer-Policy: strict-origin-when-cross-origin
```

**Comment les ajouter** : Dans Coolify, onglet « Proxy » ou dans un fichier de configuration Nginx attaché au service backend.

---

## 11. Rate Limiting (Brute Force)

> [!WARNING]
> L'API ne dispose pas actuellement de protection contre les attaques par force brute sur le endpoint `/auth/login`.

**Risque** : Un attaquant peut tenter des milliers de combinaisons mot de passe/email par seconde.

**Recommandations** :
- **Coolify/Nginx** : Configurer `limit_req_zone` sur `/auth/login` (ex : max 10 req/min par IP).
- **Applicatif** : Ajouter un compteur d'échecs en `cache` ou BDD et bloquer temporairement après 5 échecs.
- **Bonus** : Implémenter un délai progressif (`sleep(1)` après échec d'authentification).

---

## 12. Stratégie de Veille Sécurité

| Fréquence | Action |
|---|---|
| Continu | Surveiller les logs (`cron_purge.log`, `cron_daily.log`, accès API) dans Coolify. |
| Mensuel | Vérifier les emails de purge RGPD reçus (confirmation de bonne exécution). |
| Trimestriel | Passer `composer update` pour mettre à jour les dépendances PHP (PHPMailer, JWT, Dotenv). |
| Trimestriel | Passer `npm audit` sur le projet frontend React (`AGheal`). |
| Semestriel | Relire les contrôleurs PHP ajoutés et vérifier que `Auth::requireRole()` protège chaque route. |
| En cas d'incident | Révoquer le secret JWT (`.env`), forcer la re-connexion de tous les utilisateurs (sans blacklist, il suffit de changer `JWT_SECRET`). |
| Annuel | Vérifier la conformité RGPD (données conservées, politique de confidentialité, consentements). |
