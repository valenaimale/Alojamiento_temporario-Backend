<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 3: fechas ocupadas sin una reserva de la plataforma (US3.3): reservas por Booking o Airbnb (RF-16),
//mantenimiento, o la unidad fuera de servicio por un daño (RN-30). Evita las dobles reservas.
//fecha_desde y fecha_hasta son noches, las dos incluidas.
//Usa up()/down() en lugar de change() porque agrega un CHECK con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaBloqueosCalendario extends AbstractMigration
{
    public function up(): void
    {
        $this->table('bloqueos_calendario')
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('fecha_desde', 'date', ['null' => false])          //primera noche bloqueada
            ->addColumn('fecha_hasta', 'date', ['null' => false])          //última noche bloqueada
            ->addColumn('motivo', 'enum', ['values' => ['reserva_externa', 'mantenimiento', 'danio'], 'null' => false])
            ->addColumn('detalle', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('creado_por', 'integer', ['signed' => false, 'null' => false])   //propietario o administrador
            ->addColumn('creado_en', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['unidad_id', 'fecha_desde'])
            ->addForeignKey('unidad_id', 'unidades', 'id')
            ->addForeignKey('creado_por', 'usuarios', 'id')
            ->create();

        $this->execute(
            'ALTER TABLE bloqueos_calendario ADD CONSTRAINT chk_bloqueos_calendario_fechas
             CHECK (fecha_desde <= fecha_hasta)'
        );
    }

    public function down(): void
    {
        $this->table('bloqueos_calendario')->drop()->save();
    }
}
