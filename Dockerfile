FROM php:8.2-fpm

# 安裝系統依賴和 PHP 擴展
RUN apt-get update && apt-get install -y \
    libyaml-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && pecl install redis yaml \
    && docker-php-ext-enable redis yaml \
    && docker-php-ext-install pdo_mysql gd

# 安裝 Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 設置工作目錄
WORKDIR /var/www

# 複製 Composer 文件並安裝依賴
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

# 複製應用程式碼
COPY . .

# 設置權限
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# 暴露 PHP-FPM 端口
EXPOSE 9000

# 啟動 PHP-FPM
CMD ["php-fpm"]