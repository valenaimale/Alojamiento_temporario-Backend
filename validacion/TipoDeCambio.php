<?php

namespace App\Validacion;

use App\Database\Conexion;

//Pesos → wei para cobrar en ETH de prueba (plan de la entrega del 80 %, Paso 0, punto 1).
//El tipo de cambio es fijo, el parámetro pesos_por_eth (1 ETH = $ 100.000.000). 1 ETH = 10^18 wei.
//Las cuentas se hacen con bcmath (texto, sin decimales de punto flotante): un monto en wei no entra en un entero de PHP.
class TipoDeCambio
{
    private const WEI_POR_ETH = '1000000000000000000';

    //El parámetro pesos_por_eth, como texto ("100000000")
    public static function pesosPorEth(): string
    {
        $consulta = Conexion::obtener()->prepare("SELECT valor FROM parametros WHERE clave = 'pesos_por_eth'");
        $consulta->execute();
        $valor = $consulta->fetchColumn();
        if ($valor === false || !is_numeric($valor) || (float) $valor <= 0) {
            throw new \RuntimeException('Falta el parámetro pesos_por_eth (o no es un número positivo)');
        }
        return (string) $valor;
    }

    //"42000.00" pesos con 100.000.000 pesos por ETH → "420000000000000" wei (0,00042 ETH). Trunca la fracción de wei.
    public static function aWei(string $pesos, string $pesosPorEth): string
    {
        return bcdiv(bcmul($pesos, self::WEI_POR_ETH, 2), $pesosPorEth, 0);
    }
}
