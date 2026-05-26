FROM php:8.3-cli-alpine

RUN docker-php-ext-install pdo_mysql \
    && apk add --no-cache mariadb-client

WORKDIR /app

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app"]
