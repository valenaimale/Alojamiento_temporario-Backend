# Imagen de PRODUCCIÓN del backend (la usa Railway para desplegar).
# Distinta de docker/php/Dockerfile, que es solo para la demo de escalado: aquella monta el código
# desde la carpeta del proyecto; esta lo COPIA adentro de la imagen e instala las dependencias.

# ---- Etapa 1: instalar las dependencias con Composer ----
FROM composer:2 AS dependencias
WORKDIR /app
# primero solo composer.json y composer.lock: si no cambian, Docker reutiliza esta capa y no reinstala todo
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
# después el resto del código, y el autoload optimizado
COPY . .
RUN composer dump-autoload --no-dev --optimize

# ---- Etapa 2: la imagen final, solo con PHP y el código ya listo ----
# PHP >= 8.4.1: lo exigen las dependencias de Phinx (Symfony 8) que están en composer.lock
FROM php:8.5-cli
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY --from=dependencias /app /app

# Railway indica en la variable PORT en qué puerto tiene que escuchar el servidor (8000 si no está definida).
# php -S alcanza para la entrega; para tráfico real se usaría Nginx + PHP-FPM.
CMD php -S 0.0.0.0:${PORT:-8000} -t index
