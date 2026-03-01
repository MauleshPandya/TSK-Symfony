FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
    git \
    curl \
    libzip-dev \
    zip \
    unzip \
    mysql-client \
    icu-dev \
    oniguruma-dev \
    linux-headers

RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    bcmath \
    intl \
    opcache \
    sockets

# Install Redis extension
RUN apk add --no-cache pcre-dev \
    && pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- Development stage ----
FROM base AS dev

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Install Symfony CLI for dev convenience
RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.alpine.sh' | sh \
    && apk add symfony-cli || true

COPY . .
RUN composer install --no-interaction --optimize-autoloader

USER www-data

# ---- Production stage ----
FROM base AS prod

ENV APP_ENV=prod

COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/custom.ini

COPY . .
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts \
    && php bin/console cache:warmup

USER www-data
