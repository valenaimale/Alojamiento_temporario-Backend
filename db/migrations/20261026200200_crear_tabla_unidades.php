<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: cada cabaña o departamento que se alquila (US2.1).
//Tiene una sola política de cancelación y un solo % de seña (DER v2, punto 11): al crear la unidad
//se copian de parametros, y el propietario los puede cambiar (US2.8).
//La base garantiza con CHECK que la seña nunca supere la retención más baja y que la retención crezca
//al acercarse la fecha (seña <= más de 30 <= de 15 a 30 <= menos de 15).
//Usa up()/down() en lugar de change() porque agrega CHECK con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaUnidades extends AbstractMigration
{
    public function up(): void
    {
        $porcentaje = ['precision' => 5, 'scale' => 2, 'null' => false];

        $this->table('unidades')
            ->addColumn('propietario_id', 'integer', ['signed' => false, 'null' => false])   //el dueño
            ->addColumn('operador_id', 'integer', ['signed' => false, 'null' => true])       //operador de estadías (opcional); el backend valida su rol
            ->addColumn('localidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('direccion', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('capacidad', 'smallinteger', ['signed' => false, 'null' => false])  //cantidad máxima de personas
            ->addColumn('caracteristicas', 'text', ['null' => true])                         //descripción y comodidades
            ->addColumn('deposito_garantia', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true])   //informativo: se cobra por fuera (RN-04)
            ->addColumn('estado_publicacion', 'enum', [
                'values'  => ['borrador', 'publicada', 'pausada'],
                'default' => 'borrador',
                'null'    => false,
            ])
            ->addColumn('disponible', 'boolean', ['default' => true, 'null' => false])       //0 = fuera de servicio por un daño (RN-30)
            ->addColumn('motivo_no_disponible', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('porcentaje_sena', 'decimal', $porcentaje)
            ->addColumn('retencion_mas_de_30', 'decimal', $porcentaje)
            ->addColumn('retencion_de_15_a_30', 'decimal', $porcentaje)
            ->addColumn('retencion_menos_de_15', 'decimal', $porcentaje)
            ->addColumn('fecha_alta', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['localidad_id', 'estado_publicacion'])                               //para la búsqueda por localidad
            ->addForeignKey('propietario_id', 'propietarios', 'usuario_id')
            ->addForeignKey('operador_id', 'usuarios', 'id')
            ->addForeignKey('localidad_id', 'localidades', 'id')
            ->create();

        //con el orden de abajo, alcanza con controlar los dos extremos para que todos los % estén entre 0 y 100
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_porcentajes_en_rango
             CHECK (porcentaje_sena >= 0 AND retencion_menos_de_15 <= 100)'
        );
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_sena_y_politica
             CHECK (porcentaje_sena <= retencion_mas_de_30
                    AND retencion_mas_de_30 <= retencion_de_15_a_30
                    AND retencion_de_15_a_30 <= retencion_menos_de_15)'
        );
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_capacidad_y_deposito
             CHECK (capacidad >= 1 AND (deposito_garantia IS NULL OR deposito_garantia >= 0))'
        );
    }

    public function down(): void
    {
        $this->table('unidades')->drop()->save();
    }
}
