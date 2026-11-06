<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Entrega del 80 % (Rol 4): los recordatorios para que el huésped pague el saldo de su reserva (RF-25).
//
//Por qué es una tabla nueva y no estaba en el DER: el DER se hizo pensando en Mercado Pago, donde la
//plataforma guardaba la tarjeta y cobraba el saldo sola, así que lo que había que anotar eran los
//*reintentos* del cobro automático, en pagos. Con la billetera, la plataforma no puede cobrar sola:
//el huésped firma la transacción desde MetaMask. Entonces lo que la plataforma hace es *recordarle*,
//y lo que hay que anotar es cuántos recordatorios se le mandaron y cuándo, para no repetirlos cada vez
//que corre el script y para cortar después de max_recordatorios_cobro.
//
//Una fila por recordatorio enviado, numeradas de 1 en adelante. No se borran: son el historial de lo que
//se le avisó al huésped, que es lo que respalda una cancelación por falta de pago.
//
//Usa up()/down() en lugar de change() porque agrega un CHECK con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaRecordatoriosSaldo extends AbstractMigration
{
    //Crea la tabla con su CHECK (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('recordatorios_saldo')
            ->addColumn('reserva_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('numero', 'smallinteger', ['signed' => false, 'null' => false])   //1, 2, 3...
            ->addColumn('enviado_en', 'datetime', ['null' => false])                      //UTC, como el resto del back
            //el mismo recordatorio no se manda dos veces, aunque el script corra dos veces a la vez
            ->addIndex(['reserva_id', 'numero'], ['unique' => true, 'name' => 'uq_recordatorios_reserva_numero'])
            ->addForeignKey('reserva_id', 'reservas', 'id')
            ->create();

        $this->execute(
            'ALTER TABLE recordatorios_saldo ADD CONSTRAINT chk_recordatorios_numero CHECK (numero >= 1)'
        );
    }

    //Borra la tabla (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->table('recordatorios_saldo')->drop()->save();
    }
}
