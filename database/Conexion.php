<?php

namespace App\Database;

use PDO;

//Conexion crea la conexion a MySQL con PDO y la reutiliza:
//la primera vez que se pide, la crea; las siguientes devuelve la misma.
//
//De donde salen los datos de conexion:
//  - En desarrollo: de phinx.php (entorno por defecto), asi cada integrante configura su base en un solo archivo.
//  - En un contenedor (demo de Docker) o desplegado (Railway): de variables de entorno, que tienen prioridad.
//    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS y, si la base exige SSL (Aiven), DB_SSL_CA.
//    Desplegado no existe phinx.php (esta en el .gitignore): todos los datos vienen de las variables.
class Conexion
{
    private static ?PDO $pdo = null;

    public static function obtener(): PDO
    {
        if (self::$pdo === null) {
            $db = [];
            $archivoPhinx = __DIR__ . '/../phinx.php';
            if (file_exists($archivoPhinx)) {
                $config = require $archivoPhinx;
                $entornos = $config['environments'];
                $db = $entornos[$entornos['default_environment']];
            }

            $host    = getenv('DB_HOST') ?: ($db['host'] ?? 'localhost');
            $port    = getenv('DB_PORT') ?: ($db['port'] ?? '3306');
            $nombre  = getenv('DB_NAME') ?: ($db['name'] ?? 'alojamiento');
            $user    = getenv('DB_USER') ?: ($db['user'] ?? 'root');
            $pass    = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($db['pass'] ?? '');
            $charset = $db['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$nombre};charset={$charset}";

            $opciones = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,     //si una consulta falla, lanza una excepcion
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, //las filas vuelven como arrays ['columna' => valor]
                PDO::ATTR_EMULATE_PREPARES => false,              //sentencias preparadas reales de MySQL
            ];

            //SSL: las bases en la nube (Aiven) exigen conexion cifrada. DB_SSL_CA (o mysql_attr_ssl_ca en phinx.php)
            //es la ruta al certificado de la autoridad que firmo el del servidor; puede ser relativa a la raiz del proyecto.
            $certificado = getenv('DB_SSL_CA') ?: ($db['mysql_attr_ssl_ca'] ?? '');
            if ($certificado !== '') {
                if (!file_exists($certificado)) {
                    $certificado = __DIR__ . '/../' . $certificado;
                }
                //PHP 8.4+ tiene Pdo\Mysql::ATTR_SSL_CA; la constante vieja PDO::MYSQL_ATTR_SSL_CA queda como respaldo
                $atributoCa = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
                $opciones[$atributoCa] = $certificado;
            }

            self::$pdo = new PDO($dsn, $user, $pass, $opciones);
        }

        return self::$pdo;
    }
}
