FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd

# Install Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Install Xdebug (conditionally based on environment)
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy existing application directory contents
COPY . /var/www/html

# Install dependencies
RUN composer install --no-interaction --optimize-autoloader

# Change ownership of our applications
RUN chown -R www-data:www-data /var/www/html

# Create log directory and set permissions
RUN mkdir -p /tmp/task-manager-logs && \
    chown -R www-data:www-data /tmp/task-manager-logs && \
    chmod -R 775 /tmp/task-manager-logs

# Create Xdebug output directory
RUN mkdir -p /tmp/xdebug && \
    chown -R www-data:www-data /tmp/xdebug && \
    chmod -R 775 /tmp/xdebug

# Copy Xdebug configuration
COPY xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy Apache configuration
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]