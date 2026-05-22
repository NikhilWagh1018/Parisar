FROM php:8.2-apache

# Fix Apache MPM conflict and enable mod_rewrite
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite

# Install PHP extensions needed for MySQL and other features
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Install additional utilities
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

ENV APACHE_DOCUMENT_ROOT /var/www/html

RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/assets

EXPOSE 80
CMD ["apache2-foreground"]
