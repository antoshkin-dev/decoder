FROM php:8.3-fpm
# Вклюкчаем redis на PHP
RUN apt-get update && apt-get install -y \
    $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

# Файл инициализации
COPY init.sh /usr/local/bin/init.sh

# каталог логов и права
RUN mkdir -p /var/log/php \
  && chown -R www-data:www-data /var/log/php /var/www \
  && chmod 755 /usr/local/bin/init.sh

WORKDIR /var/www

EXPOSE 9000
#CMD ["php-fpm"]
