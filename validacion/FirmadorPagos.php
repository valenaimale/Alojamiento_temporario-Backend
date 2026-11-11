<?php

namespace App\Validacion;

//Manda las transacciones que firma la billetera de la plataforma (la owner del contrato): devolver y liquidar (Rol 4).
//Plan de la entrega del 80 %: "el back firma solo" con PLATAFORMA_CLAVE_PRIVADA (variable de Railway).
//Igual que VerificadorPagos: el controlador no sabe nada de la red. Hay dos: FirmadorSimulado y FirmadorBlockchain
//(Base Sepolia), y se elige en Firmadores. La clave privada nunca vive en el código.
interface FirmadorPagos
{
    //El chain id de la red donde manda las transacciones (tiene que ser la misma del verificador)
    public function red(): int;

    //La billetera de la plataforma (la que firma, dueña del contrato): ahí va su comisión al liquidar (decisión #58)
    public function direccion(): string;

    //Manda devolver(reservaId, huesped, monto) al contrato. Si no la pudo mandar, lanza una excepción.
    public function devolver(int $reservaId, string $billetera, string $montoWei): EnvioTransaccion;

    //Manda liquidar(reservaId, destinatarios, montos) al contrato (Rol 4). $montosWei en el mismo orden que
    //$destinatarios. Si no la pudo mandar, lanza una excepción.
    public function liquidar(int $reservaId, array $destinatarios, array $montosWei): EnvioTransaccion;

    //Cómo terminó una transacción que ya se mandó: true si se confirmó, false si revirtió o la red ya no la tiene
    //(hay que volver a mandarla), null si todavía no está en un bloque.
    public function estado(string $txHash): ?bool;
}
