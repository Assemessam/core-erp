FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pcntl zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# DOM, XML, and mbstring are already included in the official PHP image.
RUN pecl install redis-6.3.0 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN groupadd --gid ${LOCAL_GID} app \
    && useradd --uid ${LOCAL_UID} --gid app --create-home app

WORKDIR /var/www/html
USER app
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
