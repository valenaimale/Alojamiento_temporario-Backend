<?php

namespace App\Validacion;

//Verificador de mentira, hasta que estén el contrato y Blockchain.php: no consulta ninguna red.
//Se guarda con red 31337, el chain id de Anvil (la red local de Foundry), para que nunca se confunda con un pago
//real de Base Sepolia (84532).
//Acepta cualquier hash con formato válido y decide por cómo termina, así se prueban los tres casos sin MetaMask:
//  - termina en "dead" → fallido: "La transacción falló en la red"
//  - termina en "0000" → pendiente (todavía no está en un bloque)
//  - cualquier otro    → confirmado
//Siempre "paga" la misma billetera de prueba. Ejemplo confirmado: 0x + 63 ceros + "1".
class VerificadorSimulado implements VerificadorPagos
{
    public const RED = 31337;
    public const BILLETERA_DE_PRUEBA = '0x000000000000000000000000000000000000beef';

    public function red(): int
    {
        return self::RED;
    }

    public function datosParaPagar(int $reservaId, string $tipo, string $montoWei): array
    {
        return ['contrato' => null, 'funcion' => $tipo === 'saldo' ? 'pagarSaldo' : 'pagarSena', 'simulado' => true];
    }

    public function verificar(string $txHash, int $reservaId, string $tipo, string $montoWei): ResultadoVerificacion
    {
        $hash = strtolower($txHash);
        if (str_ends_with($hash, 'dead')) {
            return ResultadoVerificacion::fallido('La transacción falló en la red', self::BILLETERA_DE_PRUEBA);
        }
        if (str_ends_with($hash, '0000')) {
            return ResultadoVerificacion::pendiente();
        }
        return ResultadoVerificacion::confirmado(self::BILLETERA_DE_PRUEBA);
    }
}
