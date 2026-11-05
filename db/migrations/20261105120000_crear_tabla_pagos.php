<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Entrega del 80 %: cada movimiento de dinero de una reserva, pagado con ETH de prueba en Base Sepolia
//a través del contrato PasarelaPago (docs/tareas/09-plan-entrega-80.md, paso 0).
//El contrato retiene el ETH (escrow) hasta que el huésped ingresa (liquidación al propietario y al administrador)
//o hasta que cancela (devolución de lo que no se retiene). Una fila por transferencia:
//  - sena y saldo: entran al contrato; 'billetera' es la del huésped que pagó.
//  - devolucion y liquidacion: salen del contrato; 'billetera' es la que recibe. Una liquidación paga a varios
//    en una sola transacción, así que puede haber varias filas con el mismo tx_hash (una por destinatario).
//Se guarda el monto en pesos y en wei (la unidad mínima del ETH: 1 ETH = 10^18 wei), más el tipo de cambio usado,
//así un cambio posterior del parámetro pesos_por_eth no altera los pagos hechos.
//Los pagos nunca se borran: si una transacción falla, queda 'fallido'.
//Usa up()/down() en lugar de change() porque agrega CHECKs con SQL y carga un parámetro.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaPagos extends AbstractMigration
{
    //Crea la tabla pagos y el parámetro del tipo de cambio (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('pagos')
            ->addColumn('reserva_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('tipo', 'enum', [
                'values' => ['sena', 'saldo', 'devolucion', 'liquidacion'],
                'null'   => false,
            ])
            ->addColumn('monto_pesos', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false])
            ->addColumn('monto_wei', 'decimal', ['precision' => 30, 'scale' => 0, 'null' => false])     //no entra en un BIGINT
            ->addColumn('pesos_por_eth', 'decimal', ['precision' => 18, 'scale' => 2, 'null' => false]) //foto del tipo de cambio
            ->addColumn('billetera', 'char', ['limit' => 42, 'null' => false])     //dirección 0x + 40 hexadecimales
            ->addColumn('red', 'integer', ['signed' => false, 'null' => false])    //chain id: 84532 = Base Sepolia
            ->addColumn('tx_hash', 'char', ['limit' => 66, 'null' => true])        //0x + 64 hexadecimales; NULL mientras nadie la firmó
            ->addColumn('estado', 'enum', [
                'values'  => ['pendiente', 'confirmado', 'fallido'],
                'default' => 'pendiente',
                'null'    => false,
            ])
            ->addColumn('creado_en', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('confirmado_en', 'datetime', ['null' => true])              //cuándo el back vio la transacción en un bloque
            ->addIndex(['reserva_id'])
            //una misma transacción no puede confirmar dos pagos a la misma billetera
            //(evita que alguien reuse el tx_hash de una seña para pagar otra reserva)
            ->addIndex(['tx_hash', 'billetera'], ['unique' => true, 'name' => 'uq_pagos_tx_billetera'])
            ->addForeignKey('reserva_id', 'reservas', 'id')
            ->create();

        $this->execute(
            "ALTER TABLE pagos
             ADD CONSTRAINT chk_pagos_montos CHECK (monto_pesos > 0 AND monto_wei > 0 AND pesos_por_eth > 0),
             ADD CONSTRAINT chk_pagos_billetera CHECK (billetera REGEXP '^0x[0-9a-fA-F]{40}$'),
             ADD CONSTRAINT chk_pagos_tx_hash CHECK (tx_hash IS NULL OR tx_hash REGEXP '^0x[0-9a-fA-F]{64}$'),
             ADD CONSTRAINT chk_pagos_confirmado CHECK (estado <> 'confirmado' OR (tx_hash IS NOT NULL AND confirmado_en IS NOT NULL))"
        );

        //Tipo de cambio fijo, elegido a propósito lejos del real: con 100 millones de pesos por ETH,
        //una seña de $ 100.000 son 0,001 ETH, así el ETH de prueba de los faucets alcanza para muchas pruebas.
        $this->table('parametros')->insert([
            'clave'       => 'pesos_por_eth',
            'valor'       => '100000000',
            'descripcion' => 'Pesos que vale 1 ETH de prueba para cobrar las reservas (fijo, no es el valor real)',
        ])->saveData();
    }

    //Borra la tabla y el parámetro (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->table('pagos')->drop()->save();
        $this->execute("DELETE FROM parametros WHERE clave = 'pesos_por_eth'");
    }
}
