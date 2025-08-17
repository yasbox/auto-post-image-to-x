FROM php:8.2-apache

# Install required packages and PHP extensions
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    zlib1g-dev \
    libzip-dev \
    unzip \
    git \
    ca-certificates \
    curl \
  && rm -rf /var/lib/apt/lists/*

# Configure and install GD
RUN docker-php-ext-configure gd \
    --with-jpeg \
    --with-webp \
    --with-freetype \
  && docker-php-ext-install -j$(nproc) gd

# Install additional PHP extensions
RUN docker-php-ext-install -j$(nproc) zip

# Enable Apache modules
RUN a2enmod rewrite headers

# Apache config: serve /var/www/app/public as DocumentRoot
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/app/public#g' /etc/apache2/sites-available/000-default.conf \
  && sed -i 's#<Directory /var/www/>#<Directory /var/www/>\n    AllowOverride All\n#' /etc/apache2/apache2.conf

WORKDIR /var/www

# Permissions hint (in dev containers)
RUN usermod -u 1000 www-data || true && groupmod -g 1000 www-data || true

# Healthcheck (simple)
HEALTHCHECK --interval=30s --timeout=3s CMD curl -fsS http://localhost/ || exit 1


