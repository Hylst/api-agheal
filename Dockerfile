FROM php:8.1-apache

# Activer le mod_rewrite d'Apache pour le routeur PHP (public/index.php)
RUN a2enmod rewrite

# Installer les dépendances système nécessaires pour Composer et les extensions PHP
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Installer l'extension PDO MySQL et ZIP (indispensable pour la base de données et Composer)
RUN docker-php-ext-install pdo pdo_mysql zip

# Installer Composer (gestionnaire de dépendances PHP)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Changer la racine (DocumentRoot) du serveur vers le dossier /public
# C'est la recommandation standard pour sécuriser les fichiers sources
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copier tout le code source dans le conteneur
COPY . /var/www/html/

# Installer les dépendances via Composer (sans les fichiers de dev)
# Cela va créer le dossier 'vendor' automatiquement sur le serveur
RUN composer install --no-dev --optimize-autoloader

# Donner les bonnes permissions
RUN chown -R www-data:www-data /var/www/html

# Exposer le port par défaut (Apache)
EXPOSE 80
