# Changelog - API AGHeal

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
