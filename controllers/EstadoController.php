<?php

namespace App\Controllers;

use App\Config\Config;
use App\Database\Conexion;
use App\Log\Log;

//GET /estado: el health check del backend.
//Lo consultan Railway (antes de pasarle el tráfico a una versión nueva), el balanceador de la demo
//de escalado y cualquiera que quiera saber si la API funciona.
//
//Responde 200 si el backend y la base de datos responden, y 503 si algo falla.
//Si las sesiones están en Redis y Redis no responde, ni siquiera se llega acá: bootstrap.php ya responde 503.
//No prueba funcionalidades (eso lo hacen los tests): tiene que ser rápido y no modificar nada.
class EstadoController
{
    public function estado()
    {
        $baseDeDatos = 'ok';
        try {
            Conexion::obtener()->query('SELECT 1')->fetchColumn();
        } catch (\Throwable $e) {
            $baseDeDatos = 'error';//el detalle va al log; en la respuesta no se muestra (podría revelar datos de la base)
            Log::error('Health check: la base de datos no responde', $e);
        }
        $todoBien = $baseDeDatos === 'ok';

        http_response_code($todoBien ? 200 : 503);
        echo json_encode([
            'estado'   => $todoBien ? 'ok' : 'error',
            'version'  => self::version(),
            'replica'  => gethostname(),//en Docker es el hostname de cada contenedor (backend1, backend2)
            'sesiones' => getenv('SESIONES_DRIVER') ?: Config::obtener('sesiones.driver'),
            'chequeos' => ['base_de_datos' => $baseDeDatos],
        ]);
    }

    //El commit que está corriendo, para comprobar qué versión se desplegó.
    //version.txt lo escribe el pipeline de GitHub Actions justo antes de desplegar (no está en el repo).
    //Si no existe, se usa RAILWAY_GIT_COMMIT_SHA (despliegue desde Railway) o 'local' (en la máquina de cada uno).
    private static function version(): string
    {
        $archivo = __DIR__ . '/../version.txt';
        $commit = is_file($archivo) ? trim((string) file_get_contents($archivo)) : (getenv('RAILWAY_GIT_COMMIT_SHA') ?: 'local');
        return substr($commit, 0, 7);
    }
}
