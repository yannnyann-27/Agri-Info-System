FROM php:8.3-apache
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pdo_pgsql

# Production PHP settings: errors are logged, never shown to visitors (display_errors=Off, log_errors=On)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Never serve dotfiles (.env, .git, ...) even if one ends up in the web root
RUN printf '<FilesMatch "^\\.(?!well-known)">\n    Require all denied\n</FilesMatch>\n<DirectoryMatch "/\\.git">\n    Require all denied\n</DirectoryMatch>\n' \
    > /etc/apache2/conf-available/deny-dotfiles.conf \
    && a2enconf deny-dotfiles

COPY . /var/www/html/
EXPOSE 80
# Database credentials are NOT in the image. Pass them at run time, e.g.
#   docker run -p 8080:80 --env-file .env <image>
# (or set DB_HOST / DB_USER / DB_PASSWORD ... in your hosting dashboard)