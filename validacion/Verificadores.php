<?php

namespace App\Validacion;

use App\Config\Config;

//Elige el verificador de pagos según pagos.verificador (variable PAGOS_VERIFICADOR). Por ahora el único es 'simulado'.
//Cuando esté Blockchain.php, se agrega acá ('blockchain' => new Blockchain()) y se cambia la configuración.
class Verificadores
{
    public static function configurado(): VerificadorPagos
    {
        $nombre = Config::obtener('pagos.verificador', 'simulado');//por defecto simulado: los configuracion.php viejos no tienen la clave
        return match ($nombre) {
            'simulado' => new VerificadorSimulado(),
            default    => throw new \RuntimeException("Verificador de pagos desconocido: '$nombre' (por ahora solo existe 'simulado')"),
        };
    }
}
