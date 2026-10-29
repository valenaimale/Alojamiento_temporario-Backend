<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 4: las reservas de la plataforma (DER v2, punto 3). Quién reserva qué unidad, qué fechas y la foto de las
//condiciones que aceptó: el importe, la seña y la política se copian de la unidad al reservar, así un cambio
//posterior del propietario no toca las reservas hechas.
//La fecha de egreso no es una noche: una reserva del 5 al 10 ocupa las noches 5 a 9.
//Por ahora (entrega 4, sin pagos) la reserva se crea 'pendiente' y ocupa sus noches hasta que se cancele.
//Las columnas del cobro (bloqueo_expira_en, monto_faltante, fecha_cobro) llegan con los pagos por blockchain.
//Las reservas nunca se borran: se cancelan.
//Usa up()/down() en lugar de change() porque agrega CHECKs con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaReservas extends AbstractMigration
{
    //Crea la tabla con sus CHECKs (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('reservas')
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('huesped_id', 'integer', ['signed' => false, 'null' => false])         //sale de la sesión
            ->addColumn('fecha_ingreso', 'date', ['null' => false])                            //primera noche
            ->addColumn('fecha_egreso', 'date', ['null' => false])                             //día de salida (no es una noche)
            ->addColumn('cantidad_personas', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('estado', 'enum', [
                'values'  => ['pendiente', 'confirmada', 'expirada', 'cancelada', 'finalizada'],
                'default' => 'pendiente',
                'null'    => false,
            ])
            ->addColumn('importe_total', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false])   //suma del precio de cada noche
            ->addColumn('porcentaje_sena', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false])  //foto del % de seña de la unidad
            ->addColumn('importe_sena', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false])
            ->addColumn('retencion_mas_de_30', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false])  //foto de la política
            ->addColumn('retencion_de_15_a_30', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false])
            ->addColumn('retencion_menos_de_15', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false])
            ->addColumn('politica_aceptada_en', 'datetime', ['null' => false])                 //cuándo aceptó la política
            ->addColumn('creado_en', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addIndex(['unidad_id', 'fecha_ingreso', 'fecha_egreso'])   //para ver rápido si unas fechas están libres
            ->addIndex(['huesped_id'])                                   //"Mis reservas"
            ->addForeignKey('unidad_id', 'unidades', 'id')
            ->addForeignKey('huesped_id', 'usuarios', 'id')
            ->create();

        $this->execute(
            'ALTER TABLE reservas
             ADD CONSTRAINT chk_reservas_fechas CHECK (fecha_ingreso < fecha_egreso),
             ADD CONSTRAINT chk_reservas_personas CHECK (cantidad_personas BETWEEN 1 AND 50),
             ADD CONSTRAINT chk_reservas_sena CHECK (importe_sena >= 0 AND importe_sena <= importe_total)'
        );
    }

    //Borra la tabla (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->table('reservas')->drop()->save();
    }
}
