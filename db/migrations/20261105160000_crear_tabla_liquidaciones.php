<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Entrega del 80 % (Rol 4): el reparto del dinero de cada estadía (RF-09, RF-13 y RF-19, tabla del DER v2).
//Una fila por reserva: cuánto se le cobró al huésped, cuánto de eso es IVA, sobre qué base se calculan las
//comisiones, la comisión de la plataforma, la del administrador y lo que le queda al propietario.
//
//Guarda una copia de todo lo que puede cambiar después (la condición fiscal del propietario y los dos
//porcentajes de comisión), por el mismo motivo que las reservas copian el precio y la política: si Cacho
//renegocia su comisión el mes que viene, las liquidaciones ya hechas no se mueven.
//
//Las dos reglas del contador viven en los CHECK, así la base no deja guardar una liquidación que no cierre:
//  - base_comisiones + importe_iva = importe_cobrado    (RN-24: si discrimina IVA, las comisiones van sobre el
//    neto; si no, sobre el total, y entonces el IVA es 0)
//  - importe_propietario = importe_cobrado - comision_plataforma - comision_administrador
//    (el IVA se lo queda el propietario, que es quien lo tiene que pagar)
//
//El estado 'retenida_por_deuda' es para RN-16, "no se liquida lo que todavía no se cobró": una estadía con
//parte del saldo sin pagar se liquida igual, pero marcada, y el propietario ve por qué.
//
//Usa up()/down() en lugar de change() porque agrega CHECKs con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaLiquidaciones extends AbstractMigration
{
    //Crea la tabla con sus claves foráneas y sus CHECKs (vendor/bin/phinx migrate)
    public function up(): void
    {
        $importe = ['precision' => 12, 'scale' => 2, 'null' => false];
        $porcentaje = ['precision' => 5, 'scale' => 2, 'null' => false];

        $this->table('liquidaciones')
            ->addColumn('reserva_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('propietario_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('administrador_id', 'integer', ['signed' => false, 'null' => true])   //NULL si la unidad es de autogestión
            ->addColumn('cobra_iva', 'boolean', ['null' => false])                           //copia de la condición fiscal al liquidar
            ->addColumn('porcentaje_iva', 'decimal', $porcentaje + ['default' => 0])         //el IVA del momento; 0 si no lo discrimina
            ->addColumn('importe_cobrado', 'decimal', $importe)                              //lo que pagó el huésped por la estadía
            ->addColumn('importe_iva', 'decimal', $importe + ['default' => 0])               //el IVA incluido en el precio, si discrimina
            ->addColumn('base_comisiones', 'decimal', $importe)                              //neto sin IVA si discrimina; si no, el total
            ->addColumn('porcentaje_comision_plataforma', 'decimal', $porcentaje)
            ->addColumn('comision_plataforma', 'decimal', $importe)
            ->addColumn('porcentaje_comision_administrador', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('comision_administrador', 'decimal', $importe + ['default' => 0])
            ->addColumn('importe_propietario', 'decimal', $importe)
            ->addColumn('estado', 'enum', [
                'values'  => ['pendiente', 'retenida_por_deuda', 'pagada'],
                'default' => 'pendiente',
                'null'    => false,
            ])
            ->addColumn('liquidada_en', 'datetime', ['null' => true])     //cuándo se le transfirió al propietario
            ->addColumn('comprobante_url', 'string', ['limit' => 500, 'null' => true])   //por ahora el comprobante se arma en el front
            ->addColumn('creado_en', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addIndex(['reserva_id'], ['unique' => true])                //una estadía se liquida una sola vez
            ->addIndex(['propietario_id', 'estado'])                      //las liquidaciones del propietario (RF-13)
            ->addIndex(['administrador_id'])                              //las comisiones del administrador (RF-09)
            ->addForeignKey('reserva_id', 'reservas', 'id')
            ->addForeignKey('propietario_id', 'propietarios', 'usuario_id')
            ->addForeignKey('administrador_id', 'administradores', 'usuario_id')
            ->create();

        $this->execute(
            'ALTER TABLE liquidaciones
             ADD CONSTRAINT chk_liquidaciones_importes
             CHECK (importe_cobrado >= 0 AND importe_iva >= 0 AND base_comisiones >= 0
                    AND comision_plataforma >= 0 AND comision_administrador >= 0 AND importe_propietario >= 0),
             ADD CONSTRAINT chk_liquidaciones_porcentajes
             CHECK (porcentaje_iva >= 0 AND porcentaje_iva <= 100
                    AND porcentaje_comision_plataforma >= 0 AND porcentaje_comision_plataforma <= 100
                    AND (porcentaje_comision_administrador IS NULL
                         OR (porcentaje_comision_administrador > 0 AND porcentaje_comision_administrador < 100))),
             ADD CONSTRAINT chk_liquidaciones_iva
             CHECK (base_comisiones + importe_iva = importe_cobrado
                    AND (cobra_iva = 1 OR (importe_iva = 0 AND porcentaje_iva = 0))),
             ADD CONSTRAINT chk_liquidaciones_reparto
             CHECK (importe_propietario = importe_cobrado - comision_plataforma - comision_administrador),
             ADD CONSTRAINT chk_liquidaciones_administrador
             CHECK (administrador_id IS NOT NULL
                    OR (porcentaje_comision_administrador IS NULL AND comision_administrador = 0)),
             ADD CONSTRAINT chk_liquidaciones_pagada
             CHECK (estado <> \'pagada\' OR liquidada_en IS NOT NULL)'
        );
    }

    //Borra la tabla (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->table('liquidaciones')->drop()->save();
    }
}
