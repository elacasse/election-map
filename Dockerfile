FROM php:8.5-cli

ARG UID=1000
ARG GID=1000

RUN groupadd -g ${GID} appuser \
    && useradd -m -u ${UID} -g ${GID} -s /bin/bash appuser

RUN printf '%s\n' \
    'export TERM=xterm-256color' \
    'if command -v dircolors >/dev/null 2>&1; then eval "$(dircolors -b)"; fi' \
    "alias ls='ls --color=auto'" \
    "alias ll='ls -lah --color=auto'" \
    >> /root/.bashrc

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

WORKDIR /var/www/election-map

EXPOSE 8000 5173
