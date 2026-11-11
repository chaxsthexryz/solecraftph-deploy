FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev ca-certificates \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
 && a2enmod rewrite headers expires \
 && rm -rf /var/lib/apt/lists/*
# Render terminates TLS; tell PHP the request was https.
RUN printf '<Directory /var/www/html>\n AllowOverride All\n Require all granted\n</Directory>\nSetEnvIf X-Forwarded-Proto "^https$" HTTPS=on\n' > /etc/apache2/conf-enabled/site.conf
COPY site/ /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh && chown -R www-data:www-data /var/www/html/uploads
ENTRYPOINT ["docker-entrypoint.sh"]
