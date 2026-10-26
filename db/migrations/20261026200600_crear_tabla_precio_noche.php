<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: el precio de cada noche de cada unidad, con su historial (US2.7, DER v2 punto 10).
//El propietario carga un rango y el sistema crea una fila por noche.
//Un precio guardado nunca se modifica ni se borra: para cambiarlo se cierra la fila vigente
//(vigente_hasta = ahora) y se crea otra. vigente_hasta NULL = el precio vigente hoy.
//Así, el precio de cada noche de una reserva se reconstruye buscando el vigente cuando se reservó.
//La clave primaria incluye vigente_desde: la misma noche tiene una fila por cada precio que tuvo.
//Usa up()/down() en lugar de change() porque agrega un CHECK con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaPrecioNoche extends AbstractMigration
{
    public function up(): void
    {
        $this->table('precio_noche', ['id' => false, 'primary_key' => ['unidad_id', 'fecha', 'vigente_desde']])
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('fecha', 'date', ['null' => false])                                //qué noche
            ->addColumn('vigente_desde', 'datetime', ['null' => false])                    //desde cuándo se reserva a este precio
            ->addColumn('precio', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false])   //el final que ve el huésped (RN-13)
            ->addColumn('vigente_hasta', 'datetime', ['null' => true])                     //NULL = vigente hoy
            ->addColumn('creado_por', 'integer', ['signed' => false, 'null' => false])     //quién cargó el precio
            ->addForeignKey('unidad_id', 'unidades', 'id')
            ->addForeignKey('creado_por', 'usuarios', 'id')
            ->create();

        $this->execute(
            'ALTER TABLE precio_noche ADD CONSTRAINT chk_precio_noche_valores
             CHECK (precio > 0 AND (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde))'
        );
    }

    public function down(): void
    {
        $this->table('precio_noche')->drop()->save();
    }
}
