# Changelog - API AGHeal

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
