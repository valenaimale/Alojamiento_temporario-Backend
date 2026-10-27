<?php

namespace App\Log;

//Registro de eventos del backend (logs) en formato JSON: una línea por evento, con campos fijos,
//para poder buscarlos y filtrarlos (por nivel, por petición, por usuario) en vez de leer texto suelto.
//
//Se escriben en la salida de errores (stderr):
//  - localmente, aparecen en la terminal de php -S;
//  - en producción, los junta Railway (Deployments → View logs), que reconoce las líneas JSON
//    y permite filtrar, por ejemplo, por @level:error.
//Las claves van en inglés (time, level, message) porque son las que esperan Railway y las herramientas de logs.
class Log
{
    private static ?string $idPeticion = null;

    //Identificador de la petición actual. Va en cada línea de log y en el header X-Request-Id de la respuesta:
    //si un usuario reporta un error, con ese identificador se encuentran en los logs todas las líneas de su petición.
    public static function idPeticion(): string
    {
        if (self::$idPeticion === null) {
            self::$idPeticion = bin2hex(random_bytes(8));
        }
        return self::$idPeticion;
    }

    //Registra un error (algo falló y hay que revisarlo), con la excepción si la hay
    public static function error(string $mensaje, ?\Throwable $excepcion = null, array $contexto = []): void
    {
        self::escribir(self::armar('error', $mensaje, $excepcion, $contexto));
    }

    //Registra una advertencia (algo raro que no impidió responder)
    public static function warning(string $mensaje, ?\Throwable $excepcion = null, array $contexto = []): void
    {
        self::escribir(self::armar('warning', $mensaje, $excepcion, $contexto));
    }

    //Registra un evento informativo
    public static function info(string $mensaje, array $contexto = []): void
    {
        self::escribir(self::armar('info', $mensaje, null, $contexto));
    }

    //Arma el registro sin escribirlo (separado de escribir() para poder testearlo).
    public static function armar(string $nivel, string $mensaje, ?\Throwable $excepcion = null, array $contexto = []): array
    {
        $registro = [
            'time'       => date(DATE_ATOM),
            'level'      => $nivel,
            'message'    => $mensaje,
            'request_id' => self::idPeticion(),
            'method'     => $_SERVER['REQUEST_METHOD'] ?? null,
            'path'       => isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : null,
            'user_id'    => $_SESSION['usuario']['id'] ?? null,//null si no hay sesión
        ];
        if ($excepcion !== null) {
            $registro['exception'] = get_class($excepcion);
            $registro['error']     = $excepcion->getMessage();
            $registro['file']      = $excepcion->getFile();
            $registro['line']      = $excepcion->getLine();
            $registro['trace']     = $excepcion->getTraceAsString();
        }
        if ($contexto !== []) {
            $registro['context'] = $contexto;
        }
        return $registro;
    }

    //Escribe el registro como una línea JSON en la salida de errores (la terminal o los logs de Railway)
    private static function escribir(array $registro): void
    {
        $linea = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents('php://stderr', $linea . PHP_EOL);
    }
}
