# Rapport d'Analyse : Intégrité de la Base de Données, Horodatage, Logs des Séances et Règlements

Date : 28 Mars 2026
Projet : AGHeal (v1.9.1)

## 1. Objectif de l'Audit
Ce document présente les résultats de l'audit complet du schéma de la base de données, des triggers de sécurité, ainsi que de la logique d'application associée à la journalisation (logs), aux présences (séances) et aux règlements (paiements) sur la plateforme AGHeal. L'objectif était de s'assurer de la complétude du modèle de données de production et de sa parfaite corrélation avec le backend et le frontend.

## 2. Structure SQL et Fichiers d'Initialisation
- **`init.sql`** : Le schéma de base de données est complet. Les tables `users`, `profiles`, `sessions`, `registrations`, `logs` et `payments_history` définissent un modèle relationnel robuste. Les clés étrangères (ex: `user_id`, `coach_id`, `session_id`) sont couplées à des contraintes `ON DELETE CASCADE` ou `ON DELETE SET NULL`, prévenant ainsi les enregistrements orphelins (ex: maintien de l'historique comptable même si un profil est temporairement désactivé ou supprimé).
- **`init_trigger.sql`** : Les triggers de sécurité sont correctement implémentés en base. Ils empêchent avec succès la suppression du dernier profil administrateur (`prevent_last_admin_deletion`) et la désactivation de ce dernier profil (`prevent_last_admin_status_change`), palliant ainsi le risque critique de verrouillage système (anti-lockout).
- **Correctifs Apportés** : Une faille fonctionnelle a été corrigée lors de l'audit. La règle `limit_registration_7_days` n'était pas appliquée côté backend par le `RegistrationController`. La fonction API permettait à un utilisateur malveillant de forcer une inscription à plusieurs mois. Une validation stricte interdisant l'inscription au-delà du délai dynamique de `< 7 jours` a été ajoutée.

## 3. Analyse des Logs et Présences (Séances)
**Question posée** : L'enregistrement des présences est-il complet en base de données et corrélé au back-end + front-end ?

**Validation technique : OUI, couverture complète.**
- **Base de Données (`logs`)** : Le `AttendanceController` enregistre de manière structurée chaque modification de présence.
- **Logs Physiques Double Sécurité (Fichiers JSON)** : En plus de la base de données, le code backend maintient une copie physique persistante sur le serveur hostinger sous forme de fichiers organisés par mois (`logs/sessions/YYYY-MM/`).
- **Données horodatées avec précision** : Les modifications incluent l'ID de la séance, la salle, l'heure, l'activité, le coach responsable, ainsi que la liste nominative des inscrits et présents, le tout horodaté précisément au moment où l'appel a été finalisé par le coach.
- **Corrélation Front/Back end-to-end** : Le frontend interroge et soumet parfaitement la route `AttendanceController::updateAttendance`. La boucle fonctionnelle est complète et tracée.

## 4. Analyse des Historiques et Règlements (Paiements)
**Question posée** : L'enregistrement des logs et l'horodatage des règlements sont-ils faits en base de données (type de règlement, coach, adhérent, montant, date...) avec possibilité de consultation de l'historique complet ?

**Validation technique : OUI, couverture comptable et historique validée.**
- **Base de Données (`payments_history`)** : Le `PaymentController` consigne chaque transaction ou ajout manuel dans cette table dédiée servant de livre de compte.
- **Données Capturées Exhaustives** : Le système génère un horodatage immuable (`payment_date`). Les métadonnées incluent le type de règlement exact (`payment_method`), l'identifiant du coach ayant procédé à l'encaissement (`coach_id`), l'`user_id` ciblé (ou un flag type public/walk-in le cas échéant), et le `amount` versé en euros.
- **Consultation de l'Historique Log** : Le backend expose la route API GET `PaymentController::index` permettant au requêtant une consultation granulaire du registre financier globalisée. Le frontend (sur l'écran `Payments` coach/admin) peut requêter les filtres de montants, de dates, ou la vue consolidée de l'ensemble de ces logs financiers d'encaissements.

## 5. Conclusion de l'Audit et Recommandations
L'audit permet d'affirmer un haut niveau de conception et une communication saine entre les 3 blocs (MariaDB / PHP Backend / React Frontend).
- Les actions dites "business critiques" (appel, règlement d'un client, délai d'annulation) sont correctement fiabilisées côté client, API et en base.
- L'auditabilité via logs dupliqués (JSON + DB) offre une fiabilité quasi à toute épreuve face en cas de litige adhésion ou d'erreur humaine d'un coach.

**Pistes d'améliorations futures (Faible priorité) :**
- **Trigger Anti-Rétrogradation** : Le système anti-lockout empêche la suppression du compte admin. Il pourrait être complété par un blocage lors d'une simple rétrogradation (ex: un admin veut se passer en "coach" tout en étant l'unique admin). Le code backend empêche l'action, l'avoir en trigger durcirait la DB MariaDB.
- **Archivage GDPR / Rotation** : Les présences historisées en JSON peuvent s'accumuler en masse. Il peut être judicieux de prévoir un script de purge via Coolify (ou `cron`) de ces fichiers au-delà de la conservation légale (ex: 2 ou 3 ans) pour limiter le coût de stockage VPS.
