FROM php:8.5-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    APP_DIR="/var/www/html"

# Dipendenze di sistema, estensioni PHP e Node.js 20
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    default-jre-headless \
    libcurl4-openssl-dev \
    libicu-dev \
    libonig-dev \
    libpng-dev \
    libjpeg-dev \
    libxml2-dev \
    libxslt-dev \
    libzip-dev \
    unzip \
    wget \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install \
        curl \
        ftp \
        gd \
        intl \
        mbstring \
        pdo_mysql \
        soap \
        xsl \
        zip \
    && a2enmod rewrite \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/*

# Composer dall'immagine ufficiale
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/

WORKDIR ${APP_DIR}

# Source del nostro fork (branch kartiell): il build context deve essere la root OSM
COPY . .

# Inizializzazione directory e file di configurazione vuoti richiesti da OSM
RUN git config --global --add safe.directory ${APP_DIR} \
    && mkdir -p ${APP_DIR}/files/temp ${APP_DIR}/logs \
    && touch manifest.json mariadb.json mysql.json checksum.json settings.json views.json modules.json

# Dipendenze PHP
RUN composer install --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist

# Dipendenze Node e compilazione asset (produzione)
RUN corepack enable \
    && corepack prepare yarn@4.12.0 --activate \
    && yarn install \
    && npm run build-OSM

# Permessi
RUN chown -R www-data:www-data ${APP_DIR} \
    && chmod -R 755 ${APP_DIR} \
    && chmod -R 775 ${APP_DIR}/files ${APP_DIR}/logs

EXPOSE 80
