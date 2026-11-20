# Imagen de PRODUCCIÓN del backend (la usa Railway para desplegar).
# Distinta de docker/php/Dockerfile, que es solo para la demo de escalado: aquella monta el código
# desde la carpeta del proyecto; esta lo COPIA adentro de la imagen e instala las dependencias.

# ---- Etapa 1: instalar las dependencias con Composer ----
FROM composer:2 AS dependencias
WORKDIR /app
# primero solo composer.json y composer.lock: si no cambian, Docker reutiliza esta capa y no reinstala todo
COPY composer.json composer.lock ./
# Esta imagen solo descarga las dependencias; el código corre en la imagen final (abajo), con PHP 8.5 y gmp. Por eso:
# --ignore-platform-req=ext-gmp: gmp está recién en la imagen final.
# --ignore-platform-req=php+: composer:2 trae la última versión de PHP (8.6 desde nov/2026) y algunas dependencias
#   (league/oauth2-client) piden "< 8.6". "php+" ignora solo ese tope; el mínimo de versión se sigue controlando.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-req=ext-gmp --ignore-platform-req=php+
# después el resto del código, y el autoload optimizado
COPY . .
RUN composer dump-autoload --no-dev --optimize

# ---- Etapa 2: la imagen final, solo con PHP y el código ya listo ----
# PHP >= 8.4.1: lo exigen las dependencias de Phinx (Symfony 8) que están en composer.lock
FROM php:8.5-cli
# bcmath: cuentas exactas con números grandes (pesos → wei de los pagos en ETH)
# gmp: la usa la librería de curvas elípticas con la que la plataforma firma devolver y liquidar (FirmaEthereum)
RUN apt-get update && apt-get install -y --no-install-recommends libgmp-dev && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql bcmath gmp
WORKDIR /app
COPY --from=dependencias /app /app

# Railway indica en la variable PORT en qué puerto tiene que escuchar el servidor (8000 si no está definida).
# php -S alcanza para la entrega; para tráfico real se usaría Nginx + PHP-FPM.
CMD php -S 0.0.0.0:${PORT:-8000} -t index
