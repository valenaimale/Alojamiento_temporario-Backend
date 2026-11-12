<?php

namespace App\Validacion;

//Extender una estadía en curso (US7.1, decisión #70 de docs/pagos-decisiones.md).
//Una extensión es una reserva nueva vinculada a la original (reservas.extiende_reserva_id), de las noches que siguen
//al egreso, que se paga entera en un solo pago (porcentaje_sena 100). No exige el mínimo de noches y, una vez paga,
//no se cancela. Se liquida apenas se confirma su pago, porque el ingreso de la original ya está registrado.
//FASE 0: por ahora nadie puede extender. Lo completa la rama `extension-back`.
class Extension
{
    //Si el huésped puede pedir una extensión de esta reserva. $reserva: una fila de reservas con, además,
    //ingreso_en y egreso_en de su estadía (null si no tiene). La usa GET /reservas/mias para mostrar el botón.
    public static function puedeExtender(array $reserva): bool
    {
        return false;
    }
}
