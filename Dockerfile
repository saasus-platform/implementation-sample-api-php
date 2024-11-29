# PHPイメージを使用
FROM php:8.0

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip git \
    libpq-dev \
    && docker-php-ext-install zip pdo \
    && docker-php-ext-install pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composerのインストール
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# 作業ディレクトリを設定
WORKDIR /var/www/html

# コンテナ起動時にサーバーを立ち上げる
CMD php artisan serve --host=0.0.0.0 --port=80
