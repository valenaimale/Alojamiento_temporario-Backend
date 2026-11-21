<?php

namespace App\Repository;

use App\Database\Conexion;

//Transiciones de estado de una reserva, compartidas entre el pago de una reserva común y el de una grupal.
class ReservaRepository
{
    //Vence la seña de una reserva pendiente: las fechas quedan libres de nuevo.
    public static function marcarExpirada(int $id): void
    {
        Conexion::obtener()->prepare("UPDATE reservas SET estado = 'expirada' WHERE id = ? AND estado = 'pendiente'")->execute([$id]);
    }

    //Confirma la reserva: se pagó la seña.
    public static function confirmar(int $id): void
    {
        Conexion::obtener()->prepare("UPDATE reservas SET estado = 'confirmada' WHERE id = ?")->execute([$id]);
    }

    //Deja la reserva sin nada que pagar: se pagó el saldo.
    public static function limpiarSaldo(int $id): void
    {
        Conexion::obtener()->prepare('UPDATE reservas SET monto_faltante = 0 WHERE id = ?')->execute([$id]);
    }
}
