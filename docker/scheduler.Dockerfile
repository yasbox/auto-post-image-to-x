FROM php:8.2-cli

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    zlib1g-dev \
    imagemagick \
    libmagickwand-dev \
    ca-certificates \
    curl \
  && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd \
    --with-jpeg \
    --with-webp \
    --with-freetype \
  && docker-php-ext-install -j$(nproc) gd

# Install imagick via PECL
RUN pecl install imagick \
  && docker-php-ext-enable imagick

WORKDIR /var/www

CMD ["bash", "-lc", "while true; do php /var/www/app/cron/runner.php || true; sleep 300; done"]


