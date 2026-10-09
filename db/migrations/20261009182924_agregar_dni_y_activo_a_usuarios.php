<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AgregarDniYActivoAUsuarios extends AbstractMigration
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
        //dni: acepta NULL porque los usuarios que ya existen no lo tienen cargado.
        //El indice unico no deja repetir un dni, pero si permite varios NULL.
        //activo: sirve para deshabilitar una cuenta sin borrarla. Todas arrancan habilitadas.
        $this->table('usuarios')
            ->addColumn('dni', 'string', ['limit' => 10, 'null' => true, 'after' => 'mail'])
            ->addColumn('activo', 'boolean', ['default' => true, 'null' => false])
            ->addIndex(['dni'], ['unique' => true])
            ->update();
    }
}
