ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache

ARG MODX_VERSION

# The PHP 7.4 image is based on Debian 11, which is past EOL: its security
# suite is gone from the mirrors and apt-get update fails. Use the archive.
RUN set -eux; \
    . /etc/os-release; \
    if [ "${VERSION_CODENAME}" = bullseye ]; then \
        sed -i \
            -e '/-security/d' \
            -e 's|http://deb.debian.org/debian|http://archive.debian.org/debian|g' \
            /etc/apt/sources.list; \
        echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-valid-until; \
    fi

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd opcache pdo_mysql zip; \
    rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-fetchit.ini
COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf

# MODX is baked into the image and copied into the site volume on first start,
# so "docker compose down -v" gives a clean site without a new download.
COPY _build/ci/modx-download.sh /usr/local/bin/modx-download
RUN set -eux; \
    chmod +x /usr/local/bin/modx-download; \
    modx-download "${MODX_VERSION}" /usr/src/modx

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
