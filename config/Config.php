<?php

namespace App\Config;

//Lee config/configuracion.php una sola vez y devuelve valores con notación de puntos: Config::obtener('smtp.host').
//(El archivo NO se llama config.php: en Windows chocaría con este Config.php, porque no distingue mayúsculas.)
class Config
{
    private static ?array $valores = null;

    public static function obtener(string $clave)
    {
        if (self::$valores === null) {
            $archivo = __DIR__ . '/configuracion.php';
            if (!file_exists($archivo)) {
                throw new \RuntimeException('Falta config/configuracion.php: copiá config/configuracion.example.php y completalo.');
            }
            self::$valores = require $archivo;
        }

        $valor = self::$valores;
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                throw new \RuntimeException("La clave de configuración '$clave' no existe.");
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }
}
