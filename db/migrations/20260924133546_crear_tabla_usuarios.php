<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CrearTablaUsuarios extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
         $this->table('usuarios')
            ->addColumn('nombre', 'string', ['limit' => 100])
            ->addColumn('mail', 'string', ['limit' => 255])
            ->addColumn('contrasenia', 'string', ['limit' => 255])
            ->addColumn('rol', 'enum', ['values' => ['huesped', 'propietario', 'administrador', 'operador']])
            ->addColumn('fecha_alta', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['mail'], ['unique' => true])
            ->create();
    }
}
