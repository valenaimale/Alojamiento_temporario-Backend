<?php

namespace App\Validacion;

//Una transacción que mandó la plataforma: su hash y si ya está confirmada en un bloque.
//El firmador real la devuelve sin confirmar (se manda y se confirma después); el simulado, confirmada.
final class EnvioTransaccion
{
    public function __construct(
        public readonly string $txHash,
        public readonly bool $confirmada,
    ) {
    }
}
