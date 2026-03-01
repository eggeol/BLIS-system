FROM php:8.2-fpm

# 1) Install OS packages required by common Laravel features + PHP extensions
# --no-install-recommends keeps the image smaller
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libicu-dev \
        curl \
        gnupg \
    ; \
    # Install Node.js from NodeSource repository
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - ; \
    apt-get install -y --no-install-recommends nodejs \
    ; \
    # 2) Install PHP extensions using the official helper scripts (from the PHP image docs)
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        zip \
        intl \
        bcmath \
        gd \
        opcache \
    ; \
    # 3) Clean apt cache to reduce image size
    rm -rf /var/lib/apt/lists/*

# 4) Composer (official approach: copy from composer image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# 5) Optional: use development php.ini (good for local dev)
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www

# 6) Optional but recommended for Laravel writable folders (works well with bind mounts too)
RUN mkdir -p storage bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# PHP-FPM is the default command in this image, so no CMD needed
