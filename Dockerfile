# Briefly AI — Dockerfile multi-stage
# Base image: FrankenPHP 1 on PHP 8.5 Alpine (constitution §3, tech-spec §14.2)
# Non-root user, worker mode, extensions pinées

ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1

# ─────────────────────────────────────────────
# Stage base : image racine + extensions PHP
# ─────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-alpine AS base

LABEL org.opencontainers.image.title="Briefly AI Backend"
LABEL org.opencontainers.image.description="Symfony 8 + API Platform 4 — FrankenPHP worker mode"
LABEL org.opencontainers.image.source="https://github.com/briefly-ai/backend"

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

# Dépendances système + extensions PHP
RUN apk add --no-cache \
        acl \
        bash \
        fcgi \
        git \
    && install-php-extensions \
        apcu \
        intl \
        opcache \
        pdo_pgsql \
        redis \
        zip

# Configuration PHP commune
COPY --link docker/php/app.ini $PHP_INI_DIR/app.conf.d/10-app.ini

WORKDIR /app

# ─────────────────────────────────────────────
# Stage deps : installation Composer (sans dev)
# ─────────────────────────────────────────────
FROM base AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-progress

# ─────────────────────────────────────────────
# Stage dev : avec vendor + code source monté
# (utilisé via compose.override.yaml)
# ─────────────────────────────────────────────
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=deps /app/vendor ./vendor
COPY . .

# Extensions dev (xdebug désactivé par défaut, voir compose.override.yaml)
RUN install-php-extensions xdebug

ENV APP_ENV=dev \
    XDEBUG_MODE=off

# ─────────────────────────────────────────────
# Stage prod : image de production finale
# ─────────────────────────────────────────────
FROM base AS prod

COPY --from=deps /app/vendor ./vendor
COPY . .

# OPcache preload activé seulement ici (le code + config/preload.php sont présents)
COPY --link docker/php/preload.ini $PHP_INI_DIR/app.conf.d/20-preload.ini

# Warmup cache Symfony
RUN php bin/console cache:warmup --env=prod \
    && chown -R www-data:www-data var/ public/

# Utilisateur non-root (constitution §6, OWASP #5)
USER www-data

EXPOSE 80 443

# FrankenPHP worker mode — zéro cold start (tech-spec §1.1)
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
