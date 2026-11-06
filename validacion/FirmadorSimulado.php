<?php

namespace App\Validacion;

//Firmador de mentira, hasta que estén el contrato y el firmador real: no manda nada a ninguna red.
//Usa la red 31337 (Anvil), la misma que VerificadorSimulado, y da por confirmada cada transacción con un hash al azar.
//Para probar qué pasa si la red falla: a una billetera que termina en "dead" no le puede mandar nada (lanza una excepción).
class FirmadorSimulado implements FirmadorPagos
{
    public function red(): int
    {
        return VerificadorSimulado::RED;
    }

    public function devolver(int $reservaId, string $billetera, string $montoWei): EnvioTransaccion
    {
        if (str_ends_with(strtolower($billetera), 'dead')) {
            throw new \RuntimeException('Firmador simulado: no se pudo mandar la devolución (billetera terminada en dead)');
        }
        return new EnvioTransaccion('0x' . bin2hex(random_bytes(32)), true);
    }
}
