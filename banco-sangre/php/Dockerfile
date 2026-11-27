FROM php:8.3-fpm-alpine

# Instalar dependencias del sistema para GD (JPEG/PNG/WebP), Zip e Intl
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    zip \
    unzip \
    git

# Configurar e instalar extensiones nativas de PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        gd \
        zip \
        intl \
        opcache

# Instalar Composer desde la imagen oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Ajustar permisos del usuario www-data para escribir en storage/
RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
