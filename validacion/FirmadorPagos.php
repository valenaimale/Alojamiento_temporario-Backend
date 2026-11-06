<?php

namespace App\Validacion;

//Manda las transacciones que firma la billetera de la plataforma (la owner del contrato): hoy devolver, y después
//liquidar (Rol 4). Plan de la entrega del 80 %: "el back firma solo" con PLATAFORMA_CLAVE_PRIVADA (variable de Railway).
//Igual que VerificadorPagos: el controlador no sabe nada de la red. Hoy está FirmadorSimulado; el real firma con
//una librería de Composer y se agrega en Firmadores. La clave privada nunca vive en el código.
interface FirmadorPagos
{
    //El chain id de la red donde manda las transacciones (tiene que ser la misma del verificador)
    public function red(): int;

    //Manda devolver(reservaId, huesped, monto) al contrato. Si no la pudo mandar, lanza una excepción.
    public function devolver(int $reservaId, string $billetera, string $montoWei): EnvioTransaccion;
}
