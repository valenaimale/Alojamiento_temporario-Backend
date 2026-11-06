<?php

namespace App\Validacion;

use App\Config\Config;

//Elige el firmador de la plataforma según pagos.firmador (variable PAGOS_FIRMADOR). Por ahora el único es 'simulado'.
//Cuando esté el real, se agrega acá y se cambia la configuración.
class Firmadores
{
    public static function configurado(): FirmadorPagos
    {
        $nombre = Config::obtener('pagos.firmador', 'simulado');//por defecto simulado: los configuracion.php viejos no tienen la clave
        return match ($nombre) {
            'simulado' => new FirmadorSimulado(),
            default    => throw new \RuntimeException("Firmador de pagos desconocido: '$nombre' (por ahora solo existe 'simulado')"),
        };
    }
}
