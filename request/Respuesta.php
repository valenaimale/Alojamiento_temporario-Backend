<?php

namespace App\Request;

//Las respuestas JSON de la API, en un solo lugar: el código HTTP y el cuerpo.
//Antes cada controlador tenía su propio error() y repetía http_response_code + echo json_encode; si cambia el
//formato de las respuestas (por ejemplo, sumar un código de error), se cambia acá.
//Los controladores responden y después hacen return: no cortan la ejecución con exit.
final class Respuesta
{
    //Responde el cuerpo en JSON con ese código HTTP (200 si no se indica). $opcionesJson: las de json_encode, por
    //ejemplo JSON_UNESCAPED_UNICODE (el libro de huéspedes lo usa para que los nombres viajen tal cual).
    public static function json(array $cuerpo, int $codigo = 200, int $opcionesJson = 0): void
    {
        http_response_code($codigo);
        echo json_encode($cuerpo, $opcionesJson);
    }

    //Responde un error: {"error": $mensaje} con ese código HTTP (422 si no se indica), más los datos de $extra
    //que necesite el front para mostrarlo (por ejemplo, cuánto falta pagar del saldo).
    public static function error(string $mensaje, int $codigo = 422, array $extra = []): void
    {
        self::json(['error' => $mensaje] + $extra, $codigo);
    }
}
