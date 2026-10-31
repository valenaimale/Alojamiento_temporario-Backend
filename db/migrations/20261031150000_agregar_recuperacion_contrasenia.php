<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AgregarRecuperacionContrasenia extends AbstractMigration
{
    public function change(): void
    {
        $this->table('usuarios')
            ->addColumn('version_sesion', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->update();

        $this->table('recuperaciones_contrasenia')
            ->addColumn('usuario_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('expira_en', 'datetime', ['null' => false])
            ->addColumn('creado_en', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['usuario_id'], ['unique' => true])
            ->addForeignKey('usuario_id', 'usuarios', 'id', ['delete' => 'CASCADE'])
            ->create();

        // Contadores compartidos por todas las réplicas, sin guardar mails ni IP en texto plano.
        $this->table('limites_recuperacion', ['id' => false, 'primary_key' => ['clave']])
            ->addColumn('clave', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('inicio', 'datetime', ['null' => false])
            ->addColumn('intentos', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addIndex(['inicio'])
            ->create();
    }
}
