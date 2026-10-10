# Imagen de cada réplica del backend: PHP con la extensión para conectarse a MySQL.
# El código no se copia acá: docker-compose lo monta desde la carpeta del proyecto (incluido vendor/).
# La versión de PHP tiene que ser >= 8.4.1: lo exigen las dependencias de Phinx (Symfony 8) en composer.lock.
FROM php:8.5-cli
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
CMD ["php", "-S", "0.0.0.0:8000", "-t", "index"]
