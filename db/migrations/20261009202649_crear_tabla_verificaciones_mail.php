<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Tabla de los enlaces de verificación de mail (Tarea 2).
//Se guarda el HASH del token (sha256), nunca el token: si alguien leyera la tabla,
//no podría usar los enlaces. Es el mismo criterio que con las contraseñas.
//El token es de un solo uso (usado_en) y vence a las 24 horas (expira_en).
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaVerificacionesMail extends AbstractMigration
{
    public function change(): void
    {
        $this->table('verificaciones_mail')
            ->addColumn('usuario_id', 'integer', ['signed' => false, 'null' => false])   //signed=false: usuarios.id es int unsigned
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])          //sha256 en hexadecimal: siempre 64 caracteres
            ->addColumn('expira_en', 'datetime', ['null' => false])
            ->addColumn('usado_en', 'datetime', ['null' => true])                        //NULL = todavía no se usó
            ->addColumn('creado_en', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['usuario_id'])
            ->addForeignKey('usuario_id', 'usuarios', 'id', ['delete' => 'CASCADE'])     //si se borra el usuario, se borran sus tokens
            ->create();
    }
}
