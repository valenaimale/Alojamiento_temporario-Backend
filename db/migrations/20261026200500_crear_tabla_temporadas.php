<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: los períodos de cada unidad, con su mínimo de noches (US2.7, US2.9, RN-03).
//No tiene precio: el precio está en precio_noche (DER v2, punto 10).
//fecha_inicio y fecha_fin son noches, las dos incluidas.
//Que dos temporadas de la misma unidad no se superpongan lo valida el backend.
//Usa up()/down() en lugar de change() porque agrega un CHECK con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaTemporadas extends AbstractMigration
{
    public function up(): void
    {
        $this->table('temporadas')
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('nombre', 'enum', ['values' => ['alta', 'media', 'baja'], 'null' => false])   //agrupa las estadísticas (RF-12)
            ->addColumn('fecha_inicio', 'date', ['null' => false])                                    //primera noche
            ->addColumn('fecha_fin', 'date', ['null' => false])                                       //última noche
            ->addColumn('minimo_noches', 'smallinteger', ['signed' => false, 'default' => 1, 'null' => false])
            ->addIndex(['unidad_id', 'fecha_inicio'])
            ->addForeignKey('unidad_id', 'unidades', 'id')
            ->create();

        $this->execute(
            'ALTER TABLE temporadas ADD CONSTRAINT chk_temporadas_fechas_y_minimo
             CHECK (fecha_inicio <= fecha_fin AND minimo_noches >= 1)'
        );
    }

    public function down(): void
    {
        $this->table('temporadas')->drop()->save();
    }
}
