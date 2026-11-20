<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Unidades grupales (US4.4, mensaje 5 de la nota de relevamiento: Cacho y las familias que alquilan varias cabañas).
//Una unidad grupal es una publicación que arma un administrador con unidades que él administra (pueden ser de
//propietarios distintos). No es una unidad nueva: el huésped la encuentra en la búsqueda, armada en el momento con
//las unidades del grupo que están libres en sus fechas, y elige cuáles reservar (decisiones del 20/11).
//  - unidades_grupales: el título (no lleva descripción: se muestra la de cada unidad), su localidad y si está activa.
//    Todas sus unidades son de esa localidad (lo controla el back al guardarla).
//  - unidades_grupales_unidades: qué unidades tiene. Una unidad puede estar en más de una grupal.
//Si el administrador deja de administrar una unidad, sale sola del grupo: el back solo usa las unidades que el
//administrador de la grupal sigue administrando (administraciones), así no hace falta borrar filas.
final class CrearUnidadesGrupales extends AbstractMigration
{
    //Crea las dos tablas; Phinx sabe deshacerlas solo (rollback) porque usa change()
    public function change(): void
    {
        $this->table('unidades_grupales')
            ->addColumn('administrador_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('localidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('titulo', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('activa', 'boolean', ['default' => true, 'null' => false])   //0 = no aparece en la búsqueda
            ->addColumn('creado_en', 'datetime', ['null' => false])
            ->addIndex(['administrador_id'])                                          //las grupales de cada administrador
            ->addIndex(['localidad_id', 'activa'])                                    //la búsqueda
            ->addForeignKey('administrador_id', 'administradores', 'usuario_id')
            ->addForeignKey('localidad_id', 'localidades', 'id')
            ->create();

        $this->table('unidades_grupales_unidades', ['id' => false, 'primary_key' => ['grupal_id', 'unidad_id']])
            ->addColumn('grupal_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['unidad_id'])
            ->addForeignKey('grupal_id', 'unidades_grupales', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('unidad_id', 'unidades', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
