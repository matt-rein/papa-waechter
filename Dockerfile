# syntax=docker/dockerfile:1

##
## FrankenPHP-Image für den Papa-Wächter.
## Ein einzelner Dienst (Webserver + PHP) mit automatischem HTTPS.
##

FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

# Standardmäßig Produktions-Modus (Compose überschreibt das lokal mit "dev").
ENV APP_ENV=prod
ENV APP_DEBUG=0

# System- und PHP-Erweiterungen. pdo_sqlite/opcache sind für SQLite bzw. Tempo,
# gd/exif fürs Verkleinern und Geraderücken der hochgeladenen Familienfotos.
RUN install-php-extensions \
        intl \
        opcache \
        zip \
        pdo_sqlite \
        gd \
        exif

# Produktions-taugliche php.ini als Ausgangsbasis + eigene Overrides.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

# Caddy-/FrankenPHP-Konfiguration.
COPY frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# Composer aus dem offiziellen Image übernehmen.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Abhängigkeiten (eigene Schicht für besseres Caching) ---
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --no-autoloader

# --- Anwendungscode ---
COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd

# Skripte ausführbar machen.
RUN chmod +x docker/entrypoint.sh docker/check-alive-loop.sh docker/cron-healthcheck.sh

# Schreibbare Verzeichnisse: var/ für SQLite, Cache und Logs, photos/ für die
# Familienfotos (kommt in Produktion aus einem Mount).
RUN mkdir -p var/cache var/log var/photo-cache data photos \
    && chown -R www-data:www-data var data photos

# Migrationen ausführen, dann den Server starten.
ENTRYPOINT ["docker/entrypoint.sh"]

# FrankenPHP lauscht standardmäßig auf 80 (HTTP) und 443 (HTTPS).
EXPOSE 80
EXPOSE 443
EXPOSE 443/udp
