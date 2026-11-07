<?php

namespace App\Validacion;

use App\Config\Config;

//Elige el firmador de la plataforma según pagos.firmador (variable PAGOS_FIRMADOR):
//'simulado' (por defecto) o 'blockchain' (Base Sepolia, con PLATAFORMA_CLAVE_PRIVADA).
class Firmadores
{
    public static function configurado(): FirmadorPagos
    {
        $nombre = Config::obtener('pagos.firmador', 'simulado');//por defecto simulado: los configuracion.php viejos no tienen la clave
        return match ($nombre) {
            'simulado'   => new FirmadorSimulado(),
            'blockchain' => new FirmadorBlockchain(),
            default      => throw new \RuntimeException("Firmador de pagos desconocido: '$nombre' (existen 'simulado' y 'blockchain')"),
        };
    }
}
