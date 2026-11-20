# Imagen para Render (onrender.com) u otro servicio que ejecute contenedores Docker
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev ca-certificates \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: permitir .htaccess y escuchar en el puerto que asigna Render ($PORT)
RUN a2enmod headers rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
 && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/servername.conf \
 && echo 'ServerSignature Off' >> /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/apache-proxy.conf /etc/apache2/conf-available/proxy-redirects.conf
RUN a2enconf proxy-redirects
COPY --chown=www-data:www-data . /var/www/html/

ENV PORT=10000 \
    TRUST_PROXY=1
EXPOSE 10000
