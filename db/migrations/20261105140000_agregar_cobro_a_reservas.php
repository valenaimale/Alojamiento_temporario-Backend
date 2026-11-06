<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Entrega del 80 % (Rol 2, L2-28): las columnas del cobro en reservas. La tabla pagos es aparte (crear_tabla_pagos).
//- bloqueo_expira_en: hasta cuándo una reserva pendiente guarda sus noches mientras el huésped paga la seña
//  (parámetro minutos_bloqueo_reserva). Vencido ese plazo deja de ocupar y se muestra 'expirada'.
//  NULL = sin vencimiento: las pendientes que ya existían antes de esta migración (no tenían plazo al crearse).
//- monto_faltante: el saldo, lo que queda por pagar después de la seña (importe_total - importe_sena).
//- fecha_cobro: el día en que se cobra el saldo, dias_cobro_saldo antes del ingreso (o el día de la reserva,
//  si se reservó con menos anticipación). Los tramos de la política de cancelación se cuentan hasta esta fecha.
//Usa up()/down() en lugar de change() porque completa las reservas que ya existen.
//OJO: en esta versión de Phinx las columnas son NULL por defecto.
final class AgregarCobroAReservas extends AbstractMigration
{
    //Agrega las columnas y las completa en las reservas existentes (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('reservas')
            ->addColumn('bloqueo_expira_en', 'datetime', ['null' => true, 'after' => 'politica_aceptada_en'])//UTC
            ->addColumn('monto_faltante', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'after' => 'importe_sena'])
            ->addColumn('fecha_cobro', 'date', ['null' => true, 'after' => 'monto_faltante'])
            ->addIndex(['estado', 'bloqueo_expira_en'])
            ->update();

        $this->execute(
            "UPDATE reservas
             SET monto_faltante = importe_total - importe_sena,
                 fecha_cobro = GREATEST(
                     DATE(creado_en),
                     DATE_SUB(fecha_ingreso, INTERVAL (SELECT CAST(valor AS UNSIGNED) FROM parametros WHERE clave = 'dias_cobro_saldo') DAY)
                 )"
        );
    }

    //Borra las columnas (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->table('reservas')
            ->removeIndex(['estado', 'bloqueo_expira_en'])
            ->removeColumn('fecha_cobro')
            ->removeColumn('monto_faltante')
            ->removeColumn('bloqueo_expira_en')
            ->update();
    }
}
