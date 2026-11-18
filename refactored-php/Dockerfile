FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY src/ ./src/
COPY public/ ./public/
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
