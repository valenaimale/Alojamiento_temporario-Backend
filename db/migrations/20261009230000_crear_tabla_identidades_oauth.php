<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Tarea 4: tabla para el inicio de sesión con Google.
//El usuario se identifica por el "sub" (ID fijo en Google) y no por el mail,
//porque una persona puede cambiar el mail de su cuenta de Google.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaIdentidadesOauth extends AbstractMigration
{
    public function change(): void
    {
        //Vincula una cuenta nuestra con su identidad en un proveedor externo (hoy solo Google)
        $this->table('identidades_oauth')
            ->addColumn('usuario_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('proveedor', 'string', ['limit' => 30, 'null' => false])    //'google'
            ->addColumn('sujeto', 'string', ['limit' => 255, 'null' => false])    //el "sub": ID único y fijo del usuario en Google
            ->addColumn('creado_en', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['proveedor', 'sujeto'], ['unique' => true])
            ->addIndex(['usuario_id'])
            ->addForeignKey('usuario_id', 'usuarios', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
