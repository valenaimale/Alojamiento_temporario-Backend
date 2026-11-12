<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Los cambios de La 25 que entran el sábado 14/11 (decisiones #67 a #72 de docs/pagos-decisiones.md):
//  - No-show: unidades.hora_check_in (desde esa hora hay 24 hs para registrar el ingreso), el estado 'no_show' de
//    reservas y reservas.no_show_en (cuándo lo marcó el sistema; desde ahí corren las 48 hs de la excepción).
//  - Descuentos por estadía larga: la tabla descuentos_estadia (los escalones de cada unidad) y, en reservas, la foto
//    del descuento aplicado: importe_sin_descuento y porcentaje_descuento. importe_total pasa a ser con el descuento.
//    Las reservas de antes quedan con importe_sin_descuento en NULL y porcentaje_descuento en 0.
//  - Extender la estadía: reservas.extiende_reserva_id, la reserva que extiende una reserva de extensión.
//Usa up()/down() con SQL porque cambia un ENUM y agrega CHECK.
final class La25Sabado extends AbstractMigration
{
    //Agrega las columnas, la tabla, el estado y los CHECK (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('unidades')
            ->addColumn('hora_check_in', 'time', ['null' => false, 'default' => '14:00:00', 'after' => 'deposito_garantia'])
            ->update();

        $this->execute(
            "ALTER TABLE reservas MODIFY estado
             ENUM('pendiente', 'confirmada', 'expirada', 'cancelada', 'finalizada', 'no_show') NOT NULL DEFAULT 'pendiente'"
        );
        $this->table('reservas')
            ->addColumn('extiende_reserva_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'huesped_id'])
            ->addColumn('importe_sin_descuento', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'after' => 'estado'])
            ->addColumn('porcentaje_descuento', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false, 'default' => 0, 'after' => 'importe_sin_descuento'])
            ->addColumn('no_show_en', 'datetime', ['null' => true, 'after' => 'motivo_cancelacion'])
            ->addIndex(['extiende_reserva_id'])
            ->addForeignKey('extiende_reserva_id', 'reservas', 'id')
            ->update();
        $this->execute(
            "ALTER TABLE reservas
             ADD CONSTRAINT chk_reservas_descuento CHECK (porcentaje_descuento >= 0 AND porcentaje_descuento < 100
                 AND (importe_sin_descuento IS NULL OR importe_sin_descuento >= importe_total)),
             ADD CONSTRAINT chk_reservas_no_show CHECK (estado <> 'no_show' OR no_show_en IS NOT NULL)"
        );

        $this->table('descuentos_estadia')
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('noches_minimas', 'smallinteger', ['signed' => false, 'null' => false])//desde cuántas noches aplica
            ->addColumn('porcentaje', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false])
            ->addColumn('creado_en', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['unidad_id', 'noches_minimas'], ['unique' => true, 'name' => 'uq_descuentos_unidad_noches'])
            ->addForeignKey('unidad_id', 'unidades', 'id', ['delete' => 'CASCADE'])
            ->create();
        $this->execute(
            'ALTER TABLE descuentos_estadia
             ADD CONSTRAINT chk_descuentos_valores CHECK (noches_minimas >= 2 AND porcentaje > 0 AND porcentaje < 100)'
        );
    }

    //Deshace los cambios (vendor/bin/phinx rollback). Falla si ya hay reservas en 'no_show': no se pierden estados.
    public function down(): void
    {
        $this->table('descuentos_estadia')->drop()->save();

        $this->execute('ALTER TABLE reservas DROP CHECK chk_reservas_no_show');
        $this->execute('ALTER TABLE reservas DROP CHECK chk_reservas_descuento');
        $this->table('reservas')
            ->dropForeignKey('extiende_reserva_id')
            ->removeIndex(['extiende_reserva_id'])
            ->removeColumn('extiende_reserva_id')
            ->removeColumn('importe_sin_descuento')
            ->removeColumn('porcentaje_descuento')
            ->removeColumn('no_show_en')
            ->update();
        $this->execute(
            "ALTER TABLE reservas MODIFY estado
             ENUM('pendiente', 'confirmada', 'expirada', 'cancelada', 'finalizada') NOT NULL DEFAULT 'pendiente'"
        );

        $this->table('unidades')->removeColumn('hora_check_in')->update();
    }
}
