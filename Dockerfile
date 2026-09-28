# syntax=docker/dockerfile:1
#
# CATMS — multi-stage image, one artefact for the web and the worker.
#
# WHY MULTI-STAGE
# Composer and the build toolchain never reach the final image. That keeps the
# attack surface small and the image small, and it means the web container and
# the outbox worker container are provably running the same code — the
# difference is the command, not the artefact.

# ── Stage 1: dependencies ───────────────────────────────────────────────────
# Resolved from composer.json/composer.lock only, so this layer is cached until
# a dependency actually changes.
FROM composer:2 AS vendor

WORKDIR /app

# --no-dev keeps PHPUnit, PHPStan and PHP-CS-Fixer out of the runtime image.
# --no-scripts because there are no post-install hooks that must run at build
# time; anything that needs the application code runs from bin/ instead.
COPY composer.json composer.lock* ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --no-interaction \
      --prefer-dist \
      --no-progress

# Generate the optimised autoloader here, where composer's own dependencies are
# present, so the application stage only has to copy the result.
COPY src ./src
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


# ── Stage 2: runtime ────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS app

# nginx serves static assets and proxies PHP; the image runs both so that
# `docker compose up` needs one service rather than two.
# tzdata is required or PHP refuses to resolve Africa/Accra.
# icu-dev/oniguruma-dev are the build dependencies for intl and mbstring.
RUN set -eux; \
    apk add --no-cache nginx supervisor tzdata icu-dev oniguruma-dev; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache intl mbstring; \
    apk del --no-network .build-deps icu-dev oniguruma-dev; \
    rm -rf /tmp/*

# Fail the build rather than the deploy if the runtime cannot satisfy the
# version constraint in composer.json.
RUN php -r "exit(PHP_VERSION_ID >= 80200 ? 0 : 1);"

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Configuration for the bundled nginx and php-fpm.
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /etc/php-fpm.d/zz-catms.conf

# `storage/` is the only path the application writes to. It exists in the
# repository via .gitkeep files, and is replaced by a named volume in compose.
RUN set -eux; \
    mkdir -p storage/logs storage/cache storage/exports; \
    chown -R www-data:www-data storage; \
    chmod -R 755 storage; \
    # Application code is read-only to the web user. Nothing here needs to be
    # writable at run time, so nothing is.
    find . -type f -name '*.php' -exec chmod 644 {} +; \
    chmod 644 docker/nginx.conf

# OPcache is enabled in docker/php-fpm.conf. These are the values that matter
# for a read-mostly PHP application: keep the compiled code, never revalidate
# against disk on every request.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcatms.ini

# Declared so the image is self-describing.
LABEL org.opencontainers.image.title="CATMS" \
      org.opencontainers.image.description="Mobile-friendly classroom allocation and timetable management system" \
      org.opencontainers.image.licenses="MIT"

USER www-data

EXPOSE 9000

# The default command runs the web tier. The worker service overrides this with
# `php bin/worker.php start`.
CMD ["php-fpm", "-F"]
