<?php

namespace App\Database;

use PDO;

//Conexion crea la conexion a MySQL con PDO y la reutiliza:
//la primera vez que se pide, la crea; las siguientes devuelve la misma.
//Los datos de conexion se leen de phinx.php (entorno por defecto),
//asi cada integrante configura su base en un solo archivo.
class Conexion
{
    private static ?PDO $pdo = null;

    public static function obtener(): PDO
    {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/../phinx.php';
            $entornos = $config['environments'];
            $db = $entornos[$entornos['default_environment']];

            //Las variables de entorno (las define docker-compose) reemplazan los datos de phinx.php:
            //desde un contenedor, MySQL está en otro host y se entra con otro usuario.
            //Sin esas variables (desarrollo normal) se usa phinx.php como siempre.
            $host = getenv('DB_HOST') ?: $db['host'];
            $port = getenv('DB_PORT') ?: $db['port'];
            $user = getenv('DB_USER') ?: $db['user'];
            $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : $db['pass'];

            $dsn = "mysql:host={$host};port={$port};dbname={$db['name']};charset={$db['charset']}";

            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,     //si una consulta falla, lanza una excepcion
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, //las filas vuelven como arrays ['columna' => valor]
                PDO::ATTR_EMULATE_PREPARES => false,              //sentencias preparadas reales de MySQL
            ]);
        }

        return self::$pdo;
    }
}
