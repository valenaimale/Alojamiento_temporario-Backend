<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: fotos de cada unidad (US2.1). La imagen está en Cloudinary: acá se guarda solo su URL.
//orden 0 es la foto principal (la de la tarjeta en los resultados de búsqueda).
//Si se borra la unidad, se borran sus fotos.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaFotosUnidad extends AbstractMigration
{
    //Crea la tabla; Phinx sabe deshacerla sola (rollback) porque usa change()
    public function change(): void
    {
        $this->table('fotos_unidad')
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('url', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('orden', 'smallinteger', ['signed' => false, 'default' => 0, 'null' => false])
            ->addIndex(['unidad_id', 'orden'])
            ->addForeignKey('unidad_id', 'unidades', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
