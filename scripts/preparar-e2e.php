<?php

//Prepara la base de los tests de punta a punta (E2E) del front, con Playwright (carpeta e2e/ del repo del front):
//  1. borra y vuelve a crear la base alojamiento_e2e (nunca la de desarrollo ni la de los tests de PHPUnit);
//  2. le aplica todas las migraciones;
//  3. le carga los datos de ejemplo (db/seeds/DatosDeEjemplo.php): Marisa, Elsa, Cacho, Nadia, Juan y Lucía,
//     con la contraseña "clave-segura-1", cuatro unidades y una reserva en cada estado.
//Lo corre Playwright antes de cada ejecución (e2e/soporte/preparar.js del front). También se puede correr a mano:
//    php scripts/preparar-e2e.php
//
//Datos de conexión: las variables E2E_DB_HOST, E2E_DB_PORT, E2E_DB_USER, E2E_DB_PASS y E2E_DB_NAME (las define el CI).
//Si no están, se usan los del entorno development de phinx.php (la máquina de cada integrante), pero SIEMPRE con la
//base alojamiento_e2e. Sin E2E_DB_HOST, solo se acepta un MySQL local: así nunca se crea ni se borra nada en Aiven.

declare(strict_types=1);

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

define('RAIZ', dirname(__DIR__));
require RAIZ . '/vendor/autoload.php';

//Los datos de conexión de la base E2E: los de las variables E2E_DB_*, o los de desarrollo con otro nombre de base
function datosBaseE2e(): array
{
    $desarrollo = [];
    if (file_exists(RAIZ . '/phinx.php')) {
        $config = require RAIZ . '/phinx.php';
        $desarrollo = $config['environments']['development'] ?? [];
    }

    $datos = [
        'host' => getenv('E2E_DB_HOST') ?: ($desarrollo['host'] ?? '127.0.0.1'),
        'port' => (string) (getenv('E2E_DB_PORT') ?: ($desarrollo['port'] ?? '3306')),
        'user' => getenv('E2E_DB_USER') ?: ($desarrollo['user'] ?? 'root'),
        'pass' => getenv('E2E_DB_PASS') !== false ? getenv('E2E_DB_PASS') : ($desarrollo['pass'] ?? ''),
        'name' => getenv('E2E_DB_NAME') ?: 'alojamiento_e2e',
    ];

    //la base se BORRA en cada ejecución: estos dos controles evitan borrar una base real por error
    if (!str_ends_with($datos['name'], '_e2e')) {
        throw new RuntimeException("El nombre de la base E2E tiene que terminar en _e2e: '{$datos['name']}'");
    }
    if (getenv('E2E_DB_HOST') === false && !in_array($datos['host'], ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException(
            "phinx.php apunta a un MySQL que no es local ('{$datos['host']}'). Para los E2E usá un MySQL local, "
            . 'o indicá el servidor a propósito con E2E_DB_HOST.'
        );
    }
    return $datos;
}

//Borra la base E2E si existía y la vuelve a crear vacía
function crearBaseE2e(array $db): void
{
    $servidor = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $servidor->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
    $servidor->exec("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

//Aplica las migraciones y carga los datos de ejemplo con Phinx, igual que "phinx migrate" y "phinx seed:run"
function migrarYCargarDatos(array $db): void
{
    $config = new Config([
        'paths' => ['migrations' => RAIZ . '/db/migrations', 'seeds' => RAIZ . '/db/seeds'],
        'environments' => [
            'default_migration_table' => 'phinxlog',
            'default_environment'     => 'e2e',
            'e2e' => [
                'adapter' => 'mysql',
                'host'    => $db['host'],
                'name'    => $db['name'],
                'user'    => $db['user'],
                'pass'    => $db['pass'],
                'port'    => $db['port'],
                'charset' => 'utf8mb4',
            ],
        ],
        'version_order' => 'creation',
    ]);
    $manager = new Manager($config, new ArrayInput([]), new ConsoleOutput());
    $manager->migrate('e2e');
    $manager->seed('e2e', 'DatosDeEjemplo');
}

try {
    $db = datosBaseE2e();
    crearBaseE2e($db);
    migrarYCargarDatos($db);
    echo "Base {$db['name']} lista para los E2E.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo preparar la base de los E2E: ' . $e->getMessage() . "\n");
    exit(1);
}
