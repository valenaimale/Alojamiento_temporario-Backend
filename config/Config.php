<?php

namespace App\Config;

//Devuelve valores de configuración con notación de puntos: Config::obtener('smtp.host').
//
//De dónde sale cada valor, en este orden:
//  1. Una variable de entorno con el mismo nombre en mayúsculas y con "_" en lugar de ".":
//     'smtp.host' → SMTP_HOST, 'app.url_front' → APP_URL_FRONT, 'sesiones.driver' → SESIONES_DRIVER.
//     Así se configura el backend desplegado (Railway), sin archivos con datos sensibles.
//  2. config/configuracion.php (desarrollo; está en el .gitignore).
//  3. config/configuracion.example.php, si no existe el anterior (los valores por defecto).
//(El archivo NO se llama config.php: en Windows chocaría con este Config.php, porque no distingue mayúsculas.)
class Config
{
    private static ?array $valores = null;

    //$porDefecto: se devuelve si la clave no existe en ningún lado (para claves agregadas después,
    //que los configuracion.php viejos de cada integrante todavía no tienen).
    public static function obtener(string $clave, $porDefecto = null)
    {
        $variableEntorno = strtoupper(str_replace('.', '_', $clave));
        $deEntorno = getenv($variableEntorno);
        if ($deEntorno !== false && $deEntorno !== '') {
            return $deEntorno;
        }

        if (self::$valores === null) {
            $archivo = __DIR__ . '/configuracion.php';
            if (!file_exists($archivo)) {
                $archivo = __DIR__ . '/configuracion.example.php';
            }
            self::$valores = require $archivo;
        }

        $valor = self::$valores;
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                if (func_num_args() > 1) {
                    return $porDefecto;
                }
                throw new \RuntimeException("La clave de configuración '$clave' no existe.");
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }
}
