FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    apache2 \
    php8.1 \
    php8.1-mysql \
    php8.1-gd \
    php8.1-curl \
    php8.1-mbstring \
    php8.1-xml \
    libapache2-mod-php8.1 \
    curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

RUN { \
    echo "display_errors = Off"; \
    echo "log_errors = On"; \
    echo "error_reporting = E_ALL"; \
    echo "error_log = /var/log/apache2/php_errors.log"; \
    echo "expose_php = Off"; \
    echo "max_execution_time = 60"; \
    echo "memory_limit = 128M"; \
    echo "upload_max_filesize = 10M"; \
    echo "post_max_size = 12M"; \
    echo "session.cookie_httponly = 1"; \
    echo "session.cookie_secure = 1"; \
    echo "session.cookie_samesite = Strict"; \
    echo "session.use_strict_mode = 1"; \
    echo "session.gc_maxlifetime = 28800"; \
    echo "opcache.enable = 1"; \
    echo "opcache.memory_consumption = 64"; \
    echo "opcache.max_accelerated_files = 2000"; \
    } >> /etc/php/8.1/apache2/php.ini

WORKDIR /var/www/html
RUN rm -f /var/www/html/index.html
COPY . .

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerTokens Prod"    >> /etc/apache2/apache2.conf \
    && echo "ServerSignature Off"  >> /etc/apache2/apache2.conf \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/assets \
    && mkdir -p /var/log/apache2 \
    && chown www-data:www-data /var/log/apache2

EXPOSE 80
STOPSIGNAL SIGWINCH
USER www-data
CMD ["apache2ctl", "-D", "FOREGROUND"]