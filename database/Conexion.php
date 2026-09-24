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

            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";

            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,     //si una consulta falla, lanza una excepcion
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, //las filas vuelven como arrays ['columna' => valor]
                PDO::ATTR_EMULATE_PREPARES => false,              //sentencias preparadas reales de MySQL
            ]);
        }

        return self::$pdo;
    }
}
