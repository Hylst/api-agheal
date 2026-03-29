# Changelog - API AGHeal

## [1.9.3] - 30 Mars 2026

### 🧪 Tests & Qualité
- **`phpunit.xml`** : Schéma XSD mis à jour de `10.5` → `13.0` pour correspondre à PHPUnit 13 installé (élimine le warning de dépréciation).
- **`tests/Repositories/BaseRepositoryTest`** : Classe rendue `abstract` — élimine le warning "No tests found in class" de PHPUnit.
- **`README.md`** : Mis à jour vers la version 1.9.2, ajout de la section Repositories complète et de la section Tests.

### 🔐 Sécurité
- **`firebase/php-jwt`** : Confirmé en `v7.0.4` (CVE-2025-45769 corrigée — affecte `<7.0.0` uniquement). `composer audit` : aucune vulnérabilité détectée.

---

## [1.9.2] - 28 Mars 2026

### 🏗️ Refactoring & Séparation des Responsabilités (4 phases)

**Phase 1 – Sécurité :**
- `index.php` : Masquage `file`/`line` des messages d'erreur en production (`APP_ENV` guard).
- `index.php` : CORS durci — suppression du fallback `*`, `403` explicite sur origines inconnues, `Vary: Origin`, preflight `204`.

**Phase 2 – Sanitisation généralisée :**
- `SessionController` : `Sanitizer::text/date/time/enum` sur tous les champs d'entrée (title, date, heure, équipements, statut).
- `PaymentController` : `Sanitizer::date/positiveDecimal/enum` sur montant, dates et méthode.
- `ProfileController` : `Sanitizer::text` sur phone, organization, remarks_health, additional_info.
- `CommunicationController` : `Sanitizer::text` (content, 2000 car.) + `Sanitizer::enum` (target_type).
- `ContactController` : `Sanitizer::email` strict (rejet 422 si invalide) + `Sanitizer::text` sur name/message.

**Phase 3 – Couche Repository :**
- `src/Repositories/BaseRepository.php` : Classe abstraite (query, fetchAll, fetchOne, execute, transactions).
- `src/Repositories/UserRepository.php` : CRUD users/roles, getAllWithRoles, getCoaches, upsertPasswordReset.
- `src/Repositories/ProfileRepository.php` : findById, update (allowlist), getGroups, updateNotifications.
- `src/Repositories/PaymentRepository.php` : findAll (filtré), create, delete, toutes les agrégations du dashboard.
- `src/Repositories/SessionRepository.php` : findAll, findById, createMany, update (allowlist), delete cascade, subscribers.
- `PaymentController` : Entièrement refactorisé → injecte `PaymentRepository`.
- `AdminController` : Entièrement refactorisé → injecte `UserRepository`.

**Phase 4 – PSR-4 Autoloading :**
- `composer.json` : Namespaces `App\Controllers`, `App\Services`, `App\Helpers`, `App\Repositories`, `App\Middleware` déclarés explicitement.
- `composer dump-autoload --optimize` exécuté.
- `autoload-dev` ajouté pour les futurs tests unitaires.

**Base de données :**
- `mysql/add_security_constraints.sql` : 8 contraintes `CHECK` + 5 triggers `BEFORE INSERT/UPDATE` (dates, montants, antériorité, statuts enum).

## [1.9.1] - 26 Mars 2026

### 🔧 Architecture & SQL Centralisés
- Réécriture majeure de `mysql/seed.sql` : rendu idempotent, nettoyage de schéma, UUID valides.
- Centralisation source : fin des doublons avec le front.
- Correction `StatsController.php` : Erreur 403 coach corrigée par l'usage strict de `array_intersect` sur le tableau JWT `roles`.
- Correction `SessionController.php` : Masquage automatique garanti des séances passées (filtrage).

## [1.9.0] - Mars 2026

### ✨ Présences, Walk-ins & Statistiques
- `AttendanceController` : Nouvel endpoint pour l'appel de présences + walk-ins + horodatage d'arrivée.
- Enregistrement immédiat dans la table d'audit `logs` lors d'un appel.
- `StatsController` complet (8 endpoints) avec agrégations avancées, CSV et fichiers JSON d'appels.

## [1.8.5] - Mars 2026

### 🔐 Google OAuth 2.0
- Ajout de `GoogleAuthController` (flux OAuth complet).

## [1.8.0] - Mars 2026

### 💵 Gestion Règlements
- `PaymentController` : CRUD complet de la facturation.
- Ajout modes (chèque, espèce, virement) dans `payments_history`.

## [1.5.5] - Mars 2026

### 📧 Communications Avancées
- Push VAPID via `PushController`.
- Communications in-app, urgences, campagnes programmables et e-mails avec différé horaire.

## [1.5.3] - Mars 2026

### 🐛 Bug Fixes
- Correction 500 sur les endpoints d'inscription/désinscription. Types stricts de paramètres corrigés.

## [1.5.1] - Mars 2026

### ✨ Certificats & Alertes Expiration
- **Base de données** : Ajout des champs `medical_certificate_date`, `notify_medical_certif_email`, et `notify_expired_payment_email`.
- **CRON** : Nouvelle vérification mensuelle (M-1) pour prévenir les adhérents de l'expiration du certificat médical.
- **CRON** : Bascule automatique au statut "en_attente" à J+1 de la date de renouvellement, et alerte email aux coachs.
- **API** : Nouveaux templates d'e-mails dans `MailerService` et extension des endpoints `ClientController` et `ProfileController`.

### ⚖️ Légal
- **Licence** : Ajout d'un fichier `LICENSE` propriétaire et mise à jour des entêtes/packages.

## [1.5.0] - Mars 2026

### 📧 Notifications & Rappels (CRON)
- **MailerService** : Création d'un service central d'envoi d'e-mails via PHPMailer.
- **Tâches Quotidiennes** : Nouveau script `scripts/cron_daily.php` effectuant l'envoi des rappels de séances (adhérents, coachs) et les alertes de renouvellement à J-1.
- **Nouvelles Séances** : Notification automatique aux adhérents ayant l'option activée lors de la création de séances par un coach.

## [1.4.0] - Mars 2026

### ✨ Gestion de la Facturation & Abonnements
- **Historisation** : Nouvelle table `payments_history` et logique d'insertion automatique dans `ClientController`.
- **Automatisation** : Script `bin/check-subscriptions.php` pour les rappels J-7 et la gestion auto des expirations.
- **API Clients** : Extension de l'API pour supporter les champs `payment_status` et `renewal_date`.

### 🔐 Sécurité & Administration
- **Protection Admin** : Triggers SQL pour empêcher l'auto-suppression du rôle admin ou l'auto-blocage.
- **Audit Logging** : Système de logs centralisé pour les actions administratives sensibles.

### 🔧 Technique
- Refonte et nettoyage des scripts SQL d'initialisation (`init.sql`, `init_trigger.sql`).
- Optimisation des index sur les dates de renouvellement.
