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

# --- Dipendenze PHP ---
# Copiando solo il manifest, questo layer (e il download dei pacchetti) resta in cache
# finché composer.json non cambia: modificare il source PHP non lo invalida.
# Nel repo non c'è composer.lock; l'autoloader viene installato vuoto qui e rigenerato
# ottimizzato dopo il COPY del source (vedi sotto), per via di optimize-autoloader + autoload.files.
COPY composer.json ./
RUN composer install --no-ansi --no-interaction --no-scripts --no-autoloader --no-progress --prefer-dist

# --- Dipendenze Node ---
# Stesso principio: il layer si invalida solo se cambiano package.json o .yarnrc.yml,
# non ad ogni modifica del source.
COPY package.json .yarnrc.yml ./
RUN corepack enable \
    && corepack prepare yarn@4.12.0 --activate \
    && yarn install

# --- Source del nostro fork (branch kartiell): il build context deve essere la root OSM ---
# Da qui in poi i layer si rifanno ad ogni modifica del codice, ma le dipendenze sopra
# (vendor/ e node_modules/, esclusi da .dockerignore) restano in cache.
COPY . .

# Autoloader ottimizzato (richiede il source presente) e compilazione asset di produzione
RUN composer dump-autoload --no-ansi --no-interaction --optimize --no-scripts \
    && npm run build-OSM

# Inizializzazione directory e file di configurazione vuoti richiesti da OSM
RUN git config --global --add safe.directory ${APP_DIR} \
    && mkdir -p ${APP_DIR}/files/temp ${APP_DIR}/logs \
    && touch manifest.json mariadb.json mysql.json checksum.json settings.json views.json modules.json widgets.json

# config.inc.php persistito su volume named (osm_config -> /osm-config), fuori dall'app tree
# per non collidere con la dir applicativa /var/www/html/config usata da OSM.
RUN mkdir -p /osm-config \
    && ln -sf /osm-config/config.inc.php ${APP_DIR}/config.inc.php

# Permessi
RUN chown -R www-data:www-data ${APP_DIR} /osm-config \
    && chmod -R 755 ${APP_DIR} \
    && chmod -R 775 ${APP_DIR}/files ${APP_DIR}/logs /osm-config

EXPOSE 80
