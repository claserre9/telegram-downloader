# Telegram Downloader: web interface (default) and command line in one image.
FROM php:8.4-cli-bookworm

# gmp and bcmath speed up the Telegram login handshake; pcntl lets Ctrl+C stop the command cleanly.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgmp-dev unzip \
    && docker-php-ext-install -j"$(nproc)" gmp bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/telegram-downloader.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Files are owned by this user so the app can write its sessions, downloads and logs.
# Match it to your own user (see DOCKER_UID in the README) so the bind-mounted folders stay yours.
ARG UID=1000
ARG GID=1000
RUN groupadd --gid "$GID" app && useradd --uid "$UID" --gid "$GID" --create-home app

WORKDIR /app
COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader \
    && rm -rf /root/.composer
COPY --chown=app:app . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p sessions downloads \
    && chown -R app:app /app

USER app
ENV PHP_CLI_SERVER_WORKERS=4 \
    TELEGRAM_CLI_SESSION=sessions/cli.session
EXPOSE 8080
VOLUME ["/app/sessions", "/app/downloads"]

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
