# syntax=docker/dockerfile:1

# ---------- Base : FrankenPHP (Caddy + PHP dans un seul binaire) ----------
# Pas de Nginx + PHP-FPM : le VPS est mutualisé et son Caddy termine déjà le
# TLS (SPEC §2). Un serveur web de moins dans la chaîne.
FROM dunglas/frankenphp:1-php8.4 AS frankenphp_base

WORKDIR /app

# Pas de `VOLUME /app/var/` : en dev, où le projet est bind-monté, Docker
# créerait à cet endroit un volume anonyme appartenant à root qui MASQUE le
# var/ du poste — cache Symfony impossible à écrire, conteneur qui sort en 1.
# En production, var/ vit dans la couche du conteneur : cache reconstruit au
# build, logs envoyés sur stderr.

RUN apt-get update && apt-get install -y --no-install-recommends \
        acl file gettext git \
    && rm -rf /var/lib/apt/lists/*

# pdo_pgsql : le cœur métier interroge PostGIS en SQL natif (SPEC §2).
# Aucune extension géospatiale côté PHP — tout le spatial est dans la base.
RUN set -eux; \
    install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        zip \
        pdo_pgsql \
    ;

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/conf.d/
COPY --link frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

# ---------- Dev : le code arrive par bind-mount, rien n'est copié ----------
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev XDEBUG_MODE=off
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/conf.d/

# ---------- Prod : le code est figé dans l'image ----------
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/

# Dépendances d'abord (cache de couche), puis le code.
COPY --link composer.* symfony.lock ./
RUN set -eux; \
    composer install --no-cache --no-dev --no-autoloader --no-scripts --no-progress

COPY --link . ./
# Le gros du tri est fait par .dockerignore (tests, docs, infra, data ne sont
# même pas dans le contexte). Restent les fichiers de construction de l'image,
# qui n'ont plus d'utilité une fois l'image construite.
RUN rm -Rf frankenphp/

# Pas de `composer dump-env prod` ici, contrairement à d'autres projets du VPS :
# le .env.local.php qu'il produit fige des secrets VIDES (le .dockerignore
# exclut .env.local) et désactive la lecture des .env — un piège qui coûte cher.
# On garde le mécanisme standard : .env fournit les défauts, et les vraies
# variables d'environnement injectées par `env_file: .env.local` l'emportent.
RUN set -eux; \
    mkdir -p var/cache var/log; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer run-script --no-dev post-install-cmd; \
    chmod +x bin/console; sync;
