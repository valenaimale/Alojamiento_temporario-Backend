<?php

namespace Tests\Soporte;

use PDO;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

//Prepara lo que necesitan los tests de integración, una sola vez por ejecución de PHPUnit:
//  1. una base de datos de prueba VACÍA (por defecto alojamiento_test), con todas las migraciones aplicadas;
//  2. el backend corriendo con php -S, conectado a esa base.
//Los tests le hacen peticiones HTTP al backend, igual que el front.
//
//Datos de conexión a MySQL: las variables de entorno TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER, TEST_DB_PASS
//y TEST_DB_NAME (las define GitHub Actions). Si no están, se usan el host, el puerto, el usuario y la contraseña
//del entorno development de phinx.php (la máquina de cada integrante), pero SIEMPRE con la base alojamiento_test:
//la base de desarrollo no se toca.
final class EntornoDePrueba
{
    public const URL_FRONT        = 'http://localhost:5500';
    public const GOOGLE_CLIENT_ID = 'id-de-prueba.apps.googleusercontent.com';

    private static bool $iniciado = false;
    private static ?PDO $pdo = null;
    /** @var array<int, resource> */
    private static array $servidores = [];

    //Prepara la base de prueba y levanta el backend, una sola vez por ejecución
    public static function iniciar(): void
    {
        if (self::$iniciado) {
            return;
        }
        self::$iniciado = true;
        register_shutdown_function([self::class, 'detenerTodo']);

        self::crearBase();
        self::migrar();
        self::levantarServidor(self::puerto());
    }

    //El puerto del backend de prueba (TEST_PUERTO, o 8089)
    public static function puerto(): int
    {
        return (int) (getenv('TEST_PUERTO') ?: 8089);
    }

    //La URL del backend de prueba
    public static function urlApi(int $puerto = 0): string
    {
        return 'http://127.0.0.1:' . ($puerto ?: self::puerto());
    }

    //Los datos de conexión de la base de prueba (los de desarrollo, con otro nombre de base)
    public static function datosBase(): array
    {
        $desarrollo = [];
        if (file_exists(RAIZ . '/phinx.php')) {
            $config = require RAIZ . '/phinx.php';
            $desarrollo = $config['environments']['development'] ?? [];
        }

        $nombre = getenv('TEST_DB_NAME') ?: 'alojamiento_test';
        //la base de prueba se BORRA en cada ejecución: este control evita borrar una base real por error
        if (!str_ends_with($nombre, '_test')) {
            throw new \RuntimeException("El nombre de la base de prueba tiene que terminar en _test: '$nombre'");
        }

        return [
            'host' => getenv('TEST_DB_HOST') ?: ($desarrollo['host'] ?? '127.0.0.1'),
            'port' => (string) (getenv('TEST_DB_PORT') ?: ($desarrollo['port'] ?? '3306')),
            'user' => getenv('TEST_DB_USER') ?: ($desarrollo['user'] ?? 'root'),
            'pass' => getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : ($desarrollo['pass'] ?? ''),
            'name' => $nombre,
        ];
    }

    //Conexión a la base de prueba, para que los tests preparen datos o verifiquen lo que quedó guardado.
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = self::datosBase();
            self::$pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                $db['user'],
                $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }
        return self::$pdo;
    }

    //Levanta un backend con php -S en el puerto indicado. $variables reemplaza las variables de entorno
    //de prueba (por ejemplo, DB_PORT=1 para simular la base caída). Devuelve la ruta del archivo donde
    //quedan los logs del servidor.
    public static function levantarServidor(int $puerto, array $variables = []): string
    {
        if (self::puertoAbierto($puerto)) {
            throw new \RuntimeException("El puerto $puerto ya está ocupado: cerrá lo que esté corriendo ahí o usá TEST_PUERTO");
        }

        $db = self::datosBase();
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "alojamiento-tests-servidor-$puerto.log";
        if (file_exists($log)) {
            unlink($log);
        }

        $entorno = array_merge(getenv(), [
            'DB_HOST'                => $db['host'],
            'DB_PORT'                => $db['port'],
            'DB_USER'                => $db['user'],
            'DB_PASS'                => $db['pass'],
            'DB_NAME'                => $db['name'],
            'SESIONES_DRIVER'        => 'archivos',
            'SESIONES_COOKIE_SEGURA' => '0',
            'APP_URL_FRONT'          => self::URL_FRONT,
            'GOOGLE_CLIENT_ID'       => self::GOOGLE_CLIENT_ID,
            'GOOGLE_CLIENT_SECRET'   => 'secreto-de-prueba',
            'GOOGLE_REDIRECT_URI'    => self::urlApi($puerto) . '/oauth/google/callback',
        ], $variables);

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', RAIZ . DIRECTORY_SEPARATOR . 'index'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $tuberias,
            RAIZ,
            $entorno
        );
        if (!is_resource($proceso)) {
            throw new \RuntimeException("No se pudo levantar el servidor de prueba en el puerto $puerto");
        }
        self::$servidores[$puerto] = $proceso;

        for ($i = 0; $i < 100; $i++) {//hasta 10 segundos
            if (self::puertoAbierto($puerto)) {
                return $log;
            }
            usleep(100_000);
        }
        throw new \RuntimeException("El servidor de prueba no arrancó en el puerto $puerto. Log: " . @file_get_contents($log));
    }

    //Apaga el backend que se levantó en ese puerto
    public static function detenerServidor(int $puerto): void
    {
        if (isset(self::$servidores[$puerto])) {
            proc_terminate(self::$servidores[$puerto]);
            proc_close(self::$servidores[$puerto]);
            unset(self::$servidores[$puerto]);
        }
    }

    //Apaga todos los backends de prueba (corre al terminar los tests)
    public static function detenerTodo(): void
    {
        foreach (array_keys(self::$servidores) as $puerto) {
            self::detenerServidor($puerto);
        }
    }

    //Crea la base de prueba desde cero (la borra si ya existía)
    private static function crearBase(): void
    {
        $db = self::datosBase();
        $servidor = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $servidor->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
        $servidor->exec("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    //Aplica las migraciones de db/migrations con Phinx, igual que "vendor/bin/phinx migrate".
    private static function migrar(): void
    {
        $db = self::datosBase();
        $config = new Config([
            'paths' => ['migrations' => RAIZ . '/db/migrations'],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment'     => 'testing',
                'testing' => [
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
        (new Manager($config, new ArrayInput([]), new NullOutput()))->migrate('testing');
    }

    //true si algo ya está escuchando en ese puerto
    private static function puertoAbierto(int $puerto): bool
    {
        $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.2);
        if ($conexion === false) {
            return false;
        }
        fclose($conexion);
        return true;
    }
}
