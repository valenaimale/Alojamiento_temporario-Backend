<?php

namespace App\Validacion;

use App\Config\Config;

//Elige el verificador de pagos según pagos.verificador (variable PAGOS_VERIFICADOR):
//'simulado' (por defecto) o 'blockchain' (Base Sepolia, VerificadorBlockchain).
class Verificadores
{
    public static function configurado(): VerificadorPagos
    {
        $nombre = Config::obtener('pagos.verificador', 'simulado');//por defecto simulado: los configuracion.php viejos no tienen la clave
        return match ($nombre) {
            'simulado'   => new VerificadorSimulado(),
            'blockchain' => new VerificadorBlockchain(),
            default      => throw new \RuntimeException("Verificador de pagos desconocido: '$nombre' (existen 'simulado' y 'blockchain')"),
        };
    }
}
