# Apache組み込みのPHPイメージを使用（並列リクエスト処理のため）
FROM php:8.0-apache

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip git \
    libpq-dev \
    && docker-php-ext-install zip pdo \
    && docker-php-ext-install pdo_pgsql \
    && docker-php-ext-enable opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# OPcache 設定（開発環境向け：ファイル変更を1秒ごとに検知）
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'opcache.revalidate_freq=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Composerのインストール
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# mod_rewrite を有効化（Laravel のルーティングに必要）
RUN a2enmod rewrite

# Apache の DocumentRoot を Laravel の public/ に設定
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/>|<Directory /var/www/html/public>|g' /etc/apache2/apache2.conf \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# 作業ディレクトリを設定
WORKDIR /var/www/html
