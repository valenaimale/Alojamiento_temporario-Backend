<?php

namespace App\Validacion;

//Firmador de mentira, para desarrollo y los tests: no manda nada a ninguna red.
//Usa la red 31337 (Anvil), la misma que VerificadorSimulado, y da por confirmada cada transacción con un hash al azar.
//Para probar qué pasa si la red falla: a una billetera que termina en "dead" no le puede mandar nada (lanza una excepción).
class FirmadorSimulado implements FirmadorPagos
{
    //Una billetera de la plataforma inventada, que nadie controla (en la red simulada no se manda nada)
    public const DIRECCION = '0x0000000000000000000000000000000000031337';

    public function red(): int
    {
        return VerificadorSimulado::RED;
    }

    public function direccion(): string
    {
        return self::DIRECCION;
    }

    public function devolver(int $reservaId, string $billetera, string $montoWei): EnvioTransaccion
    {
        if (str_ends_with(strtolower($billetera), 'dead')) {
            throw new \RuntimeException('Firmador simulado: no se pudo mandar la devolución (billetera terminada en dead)');
        }
        return new EnvioTransaccion('0x' . bin2hex(random_bytes(32)), true);
    }

    public function liquidar(int $reservaId, array $destinatarios, array $montosWei): EnvioTransaccion
    {
        foreach ($destinatarios as $destinatario) {
            if (str_ends_with(strtolower($destinatario), 'dead')) {
                throw new \RuntimeException('Firmador simulado: no se pudo mandar la liquidación (billetera terminada en dead)');
            }
        }
        return new EnvioTransaccion('0x' . bin2hex(random_bytes(32)), true);
    }

    public function estado(string $txHash): ?bool
    {
        return true;
    }
}
