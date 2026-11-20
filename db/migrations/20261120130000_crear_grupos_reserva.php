<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Reserva grupal (US4.4 y US8.5): las reservas de las unidades de una grupal que reservó un huésped en una sola vez.
//Cada unidad sigue siendo una fila de reservas, con su propietario, su política y su liquidación; el grupo solo las
//junta para pagarlas en una transacción (pagarSenaGrupal y pagarSaldoGrupal del contrato) y mostrarlas juntas.
//  - grupos_reserva: quién lo reservó y desde qué unidad grupal (si el administrador la borra, el grupo queda igual).
//  - reservas.grupo_id: el grupo de la reserva, o NULL si es una reserva común.
//  - pagos: el índice único pasa de (tx_hash, billetera) a (tx_hash, billetera, reserva_id). Un pago grupal es UNA
//    transacción con un pago por reserva, todos desde la misma billetera; sigue sin poder anotarse dos veces el mismo
//    pago de una reserva, y en una liquidación sigue habiendo un pago por billetera.
final class CrearGruposReserva extends AbstractMigration
{
    //Crea la tabla, la columna y cambia el índice (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('grupos_reserva')
            ->addColumn('huesped_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('grupal_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('creado_en', 'datetime', ['null' => false])
            ->addIndex(['huesped_id'])
            ->addForeignKey('huesped_id', 'usuarios', 'id')
            ->addForeignKey('grupal_id', 'unidades_grupales', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('reservas')
            ->addColumn('grupo_id', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['grupo_id'])
            ->addForeignKey('grupo_id', 'grupos_reserva', 'id')
            ->update();

        $this->table('pagos')
            ->addIndex(['tx_hash', 'billetera', 'reserva_id'], ['unique' => true, 'name' => 'uq_pagos_tx_billetera_reserva'])
            ->update();
        $this->table('pagos')->removeIndexByName('uq_pagos_tx_billetera')->update();
    }

    //Lo deshace (vendor/bin/phinx rollback). Falla si ya hay pagos grupales: tendrían el mismo tx_hash y billetera.
    public function down(): void
    {
        $this->table('pagos')
            ->addIndex(['tx_hash', 'billetera'], ['unique' => true, 'name' => 'uq_pagos_tx_billetera'])
            ->update();
        $this->table('pagos')->removeIndexByName('uq_pagos_tx_billetera_reserva')->update();

        $this->table('reservas')->dropForeignKey('grupo_id')->removeIndex(['grupo_id'])->removeColumn('grupo_id')->update();
        $this->table('grupos_reserva')->drop()->save();
    }
}
