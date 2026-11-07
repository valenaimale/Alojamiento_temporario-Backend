<?php

namespace App\Validacion;

//Lo que responde un VerificadorPagos sobre una transacción:
//  - confirmado: pagó lo que tenía que pagar (con la billetera que pagó)
//  - pendiente: todavía no está en un bloque; el front tiene que volver a preguntar en unos segundos
//  - fallido: la transacción falló o no corresponde a ese pago, con el motivo para el huésped.
//    Trae la billetera si la transacción existe (pagos.billetera es obligatoria: sin ella el intento no se guarda).
final class ResultadoVerificacion
{
    public const CONFIRMADO = 'confirmado';
    public const PENDIENTE = 'pendiente';
    public const FALLIDO = 'fallido';

    private function __construct(
        public readonly string $estado,
        public readonly ?string $billetera,
        public readonly ?string $motivo,
        public readonly ?string $montoWei = null,//lo que pagó de verdad (el verificador real lo lee del evento)
    ) {
    }

    //$montoWei: lo que entró al contrato, si el verificador lo sabe (si no, se guarda el monto pedido)
    public static function confirmado(string $billetera, ?string $montoWei = null): self
    {
        return new self(self::CONFIRMADO, $billetera, null, $montoWei);
    }

    public static function pendiente(): self
    {
        return new self(self::PENDIENTE, null, null);
    }

    public static function fallido(string $motivo, ?string $billetera = null): self
    {
        return new self(self::FALLIDO, $billetera, $motivo);
    }
}
