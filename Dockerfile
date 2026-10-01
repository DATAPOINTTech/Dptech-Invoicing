# Production Dockerfile for DATAPOINT Invoicing System (PHP)
FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libsqlite3-dev \
    libzip-dev \
    zip \
    unzip \
    poppler-utils \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_sqlite \
    pdo_pgsql \
    pdo_mysql \
    bcmath \
    zip \
    opcache

# Enable Apache modules
RUN a2enmod rewrite headers expires

# Configure Apache DocumentRoot to point to php/public
ENV APACHE_DOCUMENT_ROOT /var/www/html/php/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure production PHP settings
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'memory_limit=256M'; \
    echo 'upload_max_filesize=32M'; \
    echo 'post_max_size=32M'; \
    echo 'max_execution_time=60'; \
    echo 'expose_php=Off'; \
    echo 'display_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'error_log=/var/log/apache2/php_errors.log'; \
} > /usr/local/etc/php/conf.d/production.ini

# Copy project files
WORKDIR /var/www/html
COPY . /var/www/html

# Set proper ownership and permissions for runtime storage
RUN mkdir -p /var/www/html/php/storage && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/php/storage

# Expose HTTP port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

CMD ["apache2-foreground"]
