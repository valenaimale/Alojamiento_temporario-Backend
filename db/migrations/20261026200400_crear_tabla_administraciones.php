<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: vínculo entre una unidad y su administrador, con su % de comisión (US2.2, US2.3).
//La clave primaria es la unidad: una unidad tiene como mucho un administrador.
//Si la unidad no tiene fila acá, es de autogestión.
//La FK apunta a administradores (no a usuarios), así la base garantiza que quien administra tenga ese rol.
//Usa up()/down() en lugar de change() porque agrega un CHECK con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaAdministraciones extends AbstractMigration
{
    public function up(): void
    {
        $this->table('administraciones', ['id' => false, 'primary_key' => ['unidad_id']])
            ->addColumn('unidad_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('administrador_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('porcentaje_comision', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false])
            ->addColumn('desde', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['administrador_id'])                                              //la cartera de cada administrador (US2.5)
            ->addForeignKey('unidad_id', 'unidades', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('administrador_id', 'administradores', 'usuario_id')
            ->create();

        //la comisión es obligatoria (RN-05): mayor que 0 y menor que 100
        $this->execute(
            'ALTER TABLE administraciones ADD CONSTRAINT chk_administraciones_comision
             CHECK (porcentaje_comision > 0 AND porcentaje_comision < 100)'
        );
    }

    public function down(): void
    {
        $this->table('administraciones')->drop()->save();
    }
}
