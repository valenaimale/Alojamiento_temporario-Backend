<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Base para las correcciones del profesor (se suma a AgregarDniYActivoAUsuarios):
//- rol backoffice: administración interna de la plataforma (no se registra desde la web).
//- mail_verificado_en: NULL hasta que el usuario confirma su mail.
//- tabla propietarios: datos fiscales. Todo usuario con rol 'propietario' tiene su fila acá.
//Usa up()/down() en lugar de change() porque migra datos.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class AgregarBackofficeVerificacionYPropietarios extends AbstractMigration
{
    public function up(): void
    {
        $this->table('usuarios')
            ->changeColumn('rol', 'enum', [
                'values'  => ['huesped', 'propietario', 'administrador', 'operador', 'backoffice'],
                'default' => 'huesped',
                'null'    => false,
            ])
            ->addColumn('mail_verificado_en', 'datetime', ['null' => true, 'after' => 'rol'])
            ->update();

        $this->table('propietarios', ['id' => false, 'primary_key' => ['usuario_id']])
            ->addColumn('usuario_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('cobra_iva', 'boolean', ['null' => false])
            ->addColumn('cuit', 'string', ['limit' => 11, 'null' => true])
            ->addColumn('razon_social', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('domicilio_fiscal', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('fecha_alta', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['cuit'], ['unique' => true])
            ->addForeignKey('usuario_id', 'usuarios', 'id', ['delete' => 'CASCADE'])
            ->create();

        //Los propietarios que ya existen (datos de prueba) pasan a la tabla nueva, sin datos fiscales
        $this->execute("INSERT INTO propietarios (usuario_id, cobra_iva) SELECT id, 0 FROM usuarios WHERE rol = 'propietario'");
    }

    //Antes de hacer rollback hay que borrar los usuarios con rol 'backoffice' (solo desarrollo)
    public function down(): void
    {
        $this->table('propietarios')->drop()->save();

        $this->table('usuarios')
            ->removeColumn('mail_verificado_en')
            ->changeColumn('rol', 'enum', [
                'values'  => ['huesped', 'propietario', 'administrador', 'operador'],
                'default' => 'huesped',
            ])
            ->update();
    }
}
