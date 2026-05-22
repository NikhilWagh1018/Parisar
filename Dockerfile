FROM php:8.2-apache

# Explicitly disable conflicting MPM modules and enable prefork
RUN apt-get update \
    && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql mysqli

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

RUN if [ -f /etc/apache2/mods-enabled/mpm_event.conf ]; then a2dismod mpm_event; fi \
    && if [ -f /etc/apache2/mods-enabled/mpm_worker.conf ]; then a2dismod mpm_worker; fi \
    && a2enmod mpm_prefork rewrite

WORKDIR /var/www/html
COPY . .

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
