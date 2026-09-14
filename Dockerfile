FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y \
        git \
        curl \
        unzip \
        libpng-dev \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        bcmath \
        pcntl \
        gd \
        zip \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

EXPOSE 8000 5173
