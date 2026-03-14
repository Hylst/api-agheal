# API AGHeal - Backend PHP

Bienvenue sur le dépôt du backend de l'application **AGHeal**. 
Cette API est développée en PHP 8.1+ et assure la gestion de la base de données MariaDB, l'authentification JWT et la logique métier du projet.

## 👤 Auteur & Droits
**Geoffroy Streit** - Développeur apprenant.
*© 2026 Geoffroy Streit. Tous droits réservés. Code source propriétaire, non libre de droits.*

## 🏗️ Architecture
- **PHP 8.1** (Apache)
- **MariaDB** (Base de données)
- **Firebase JWT** (Gestion des sessions)
- **PHPMailer** (Envoi d'e-mails)

## 🐳 Déploiement (Docker)
Ce projet est configuré pour être déployé facilement via **Docker** ou **Coolify**. Le `Dockerfile` à la racine configure automatiquement :
- Le module Apache Rewrite (pour `index.php`).
- L'extension PHP PDO MySQL.
- Le dossier `public/` comme racine du serveur.

## 🔐 Sécurité
- Les variables sensibles sont gérées via un fichier `.env` (non inclus dans le dépôt).
- Les mots de passe sont hashés via `bcrypt`.
- L'authentification est sécurisée par des tokens **JWT** (JSON Web Tokens).

## 🤖 Automatisation (CRON)
Le système inclut un script consolidé gérant toutes les notifications asynchrones :
- `scripts/cron_daily.php` : À exécuter quotidiennement (ex: 07h00). Il envoie les rappels de renouvellement d'adhésion, les e-mails de rappel de séance pour les inscrits du lendemain, et un récapitulatif du planning aux coachs concernés.

## 🚀 Installation locale
1. Clonez le dépôt.
2. Configurez votre fichier `.env` à partir de `.env.example`.
3. Lancez votre serveur PHP/Apache (WAMP ou Docker).
