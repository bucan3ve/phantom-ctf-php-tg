# Use the official PHP image with Apache
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    sqlite3 \
    && rm -rf /var/lib/apt/lists/*

# Now, install the PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Set up a writable directory for the database
RUN mkdir -p /var/www/html/db && \
    chown -R www-data:www-data /var/www/html/db && \
    chmod -R 700 /var/www/html/db

# Enable Apache rewrite module for .htaccess
RUN a2enmod rewrite
