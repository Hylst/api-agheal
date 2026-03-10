FROM php:8.1-apache

# Activer le mod_rewrite d'Apache pour le routeur PHP (public/index.php)
RUN a2enmod rewrite

# Installer l'extension PDO MySQL indispensable pour la base de données
RUN docker-php-ext-install pdo pdo_mysql

# Changer la racine (DocumentRoot) du serveur vers le dossier /public
# C'est la recommandation standard pour sécuriser les fichiers sources
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copier tout le code source dans le conteneur
COPY . /var/www/html/

# Donner les bonnes permissions
RUN chown -R www-data:www-data /var/www/html

# Exposer le port par défaut (Apache)
EXPOSE 80
