FROM php:8.1-apache

# Cài đặt Composer
RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Cài đặt và kích hoạt các extension mở rộng cho MySQLi và PDO bảo mật
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && docker-php-ext-enable mysqli pdo pdo_mysql

# Kích hoạt mod_rewrite của Apache (phục vụ cho việc định tuyến Router Whitelist mượt mà hơn)
RUN a2enmod rewrite

# Copy composer.json và chạy composer install
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Cấp quyền ghi để Apache container vận hành tệp tin mượt mà, không bị nghẽn
RUN chown -R www-data:www-data /var/www/html