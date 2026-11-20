<?php

use Phinx\Migration\AbstractMigration;

// Ubicación pública elegida por el propietario. Las unidades existentes conservan la búsqueda común.
final class UbicacionDeUnidades extends AbstractMigration
{
    // Agrega las cinco columnas, todas opcionales; Phinx sabe deshacerlas solo (rollback) porque usa change()
    public function change(): void
    {
        $this->table('unidades')
            ->addColumn('latitud', 'decimal', ['precision' => 9, 'scale' => 6, 'null' => true])
            ->addColumn('longitud', 'decimal', ['precision' => 9, 'scale' => 6, 'null' => true])
            ->addColumn('barrio', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('metros_playa', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('metros_centro', 'integer', ['signed' => false, 'null' => true])
            ->update();
    }
}
