<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AgregarEstadiasYLibro extends AbstractMigration
{
    public function change(): void
    {
        $this->table('reservas')
            ->addColumn('version_huespedes', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->update();
        $this->table('huespedes_reserva')
            ->addColumn('reserva_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('orden', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('es_titular', 'boolean', ['null' => false])
            ->addColumn('nombre', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('tipo_documento', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('documento', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('nacionalidad', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('fecha_nacimiento', 'date', ['null' => false])
            ->addColumn('domicilio', 'string', ['limit' => 250, 'null' => false])
            ->addIndex(['reserva_id', 'orden'], ['unique' => true])
            ->addForeignKey('reserva_id', 'reservas', 'id')
            ->create();
        $this->table('estadias')
            ->addColumn('reserva_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('ingreso_en', 'datetime', ['null' => false])
            ->addColumn('egreso_en', 'datetime', ['null' => true])
            ->addColumn('ingreso_registrado_en', 'datetime', ['null' => false])
            ->addColumn('egreso_registrado_en', 'datetime', ['null' => true])
            ->addColumn('ingreso_operador_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('egreso_operador_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('ingreso_evento_id', 'string', ['limit' => 36, 'null' => false])
            ->addColumn('egreso_evento_id', 'string', ['limit' => 36, 'null' => true])
            ->addColumn('estado_ingreso', 'string', ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('estado_egreso', 'string', ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('notas_ingreso', 'string', ['limit' => 1000, 'null' => false, 'default' => ''])
            ->addColumn('notas_egreso', 'string', ['limit' => 1000, 'null' => false, 'default' => ''])
            ->addIndex(['reserva_id'], ['unique' => true])
            ->addIndex(['ingreso_evento_id'], ['unique' => true])
            ->addIndex(['egreso_evento_id'], ['unique' => true])
            ->addForeignKey('reserva_id', 'reservas', 'id')
            ->addForeignKey('ingreso_operador_id', 'usuarios', 'id')
            ->addForeignKey('egreso_operador_id', 'usuarios', 'id')
            ->create();
    }
}
