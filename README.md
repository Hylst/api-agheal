# API AGHeal - Backend PHP

> **Version actuelle : 1.9.2** | [Voir le CHANGELOG](./CHANGELOG.md)

Bienvenue sur le dépôt du backend de l'application **AGHeal**.  
Cette API est développée en PHP 8.1+ et assure la gestion de la base de données MariaDB, l'authentification JWT (Google OAuth 2.0 inclus) et la logique métier du projet.

## 👤 Auteur & Droits
**Geoffroy Streit** - Développeur apprenant.  
*© 2026 Geoffroy Streit. Tous droits réservés. Code source propriétaire, non libre de droits.*

## 🏗️ Architecture
- **PHP 8.3+** (Apache)
- **MariaDB** (Base de données)
- **Firebase JWT v7+** (Gestion des sessions — CVE-2025-45769 corrigée)
- **PHPMailer** (Envoi d'e-mails)
- **Pattern Repository** (Couche d'accès aux données)

## 🗂️ Couche Repository (`src/Repositories/`)

| Classe | Responsabilité |
|--------|----------------|
| `BaseRepository` | Classe abstraite : PDO helpers, transactions |
| `UserRepository` | CRUD users, rôles, OAuth upsert |
| `ProfileRepository` | Profils, groupes, notifications |
| `SessionRepository` | Séances — CRUD, filtrage temporel |
| `AttendanceRepository` | Appels de présences, walk-ins, horodatage |
| `RegistrationRepository` | Inscriptions + verrous `FOR UPDATE` (concurrence) |
| `PaymentRepository` | Règlements, dashboard financier |
| `StatsRepository` | Agrégations BI : KPIs, présences, démographie |

## 🧪 Tests (PHPUnit)

```bash
vendor/bin/phpunit
```

- Framework : **PHPUnit 13**
- Config : `phpunit.xml` (cible `tests/Repositories/`)
- Infrastructure de tests préparée dans `tests/Repositories/` (mocks PDO)

## 🐳 Déploiement (Docker)
Ce projet est configuré pour être déployé facilement via **Docker** ou **Coolify**. Le `Dockerfile` à la racine configure automatiquement :
- Le module Apache Rewrite (pour `index.php`).
- L'extension PHP PDO MySQL.
- Le dossier `public/` comme racine du serveur.

## 🔐 Sécurité
- Les variables sensibles sont gérées via un fichier `.env` (non inclus dans le dépôt).
- Les mots de passe sont hashés via `bcrypt`.
- L'authentification est sécurisée par des tokens **JWT** (JSON Web Tokens) — `firebase/php-jwt v7+`.
- CORS configuré pour n'accepter que le domaine frontend autorisé.
- Requêtes PDO préparées contre les injections SQL.
- Contraintes CHECK SQL et triggers de validation sur la base de données.

## 🤖 Automatisation (CRON)
Le système inclut un script consolidé gérant toutes les notifications asynchrones :
- `scripts/cron_daily.php` : À exécuter quotidiennement (ex: 07h00).
    - **Rappels Séances** : Emails J-1 pour les adhérents et coachs.
    - **Renouvellement** : Emails J-7 pour les adhérents.
    - **Certificat Médical** : Rappel M-1 par email pour les adhérents.
    - **Auto-Expiration** : À J+1 de la date de renouvellement, le statut passe à "en_attente" et une alerte est envoyée aux coachs.
    - **Nouveaux Créneaux** : Notification immédiate lors de la publication (via SessionController).
    
- `scripts/cron_hourly.php` : À exécuter toutes les heures.
    - **E-mails Programmables** : Exécution des campagnes d'e-mails différées (Communications in-app).

## 🧩 Contrôleurs Principaux (API)
- `AuthController` & `GoogleAuthController` : Authentification et sessions.
- `SessionController` & `AttendanceController` : Gestion des séances, inscriptions, walk-ins et appel.
- `StatsController` : Points d'accès pour les KPIs, pyramides d'âges, logs d'appel et export CSV/JSON.
- `PaymentController` : Gestion complète de l'historique et des statuts de règlement.
- `CommunicationController` & `EmailCampaignController` : Messages in-app ciblés et différés.
- `PushController` : Gestion des web push VAPID.

## 🚀 Installation locale
1. Clonez le dépôt.
2. Configurez votre fichier `.env` à partir de `.env.example`.
3. Lancez votre serveur PHP/Apache (WAMP ou Docker).
4. Exécutez les scripts SQL dans l'ordre : `mysql/init.sql` → `mysql/init_trigger.sql` → `mysql/seed.sql` (local uniquement).
