# PHPイメージを使用
# ※ この変更は PKCE 動作確認用の暫定対応であり、main への取り込みは想定していない。
#    php:8.0 は Debian bullseye(EOL) により apt-get が 404 となりビルドできないため、
#    動作確認を通す目的で php:8.1(bookworm) に変更している。
#    本アプリ・SaaSus SDK いずれも php >=8.0.2 を要求しており 8.1 で動作する。
#    本来は別途 Dockerfile の恒久対応を検討すること。
FROM php:8.1

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
