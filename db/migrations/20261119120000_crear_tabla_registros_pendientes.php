<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Registros pendientes: verificar el mail ANTES de crear la cuenta.
//POST /registrarse ya no crea la cuenta: guarda acá los datos y manda un enlace por mail. La cuenta (usuarios, y
//propietarios o administradores) se crea recién cuando alguien abre ese enlace (POST /verificar-mail). Así un mail
//falso o ajeno nunca ocupa un mail, un DNI o un CUIT.
//  - Un mail tiene a lo sumo un registro pendiente: registrarse otra vez con el mismo mail lo reemplaza.
//  - El mail NO es único contra usuarios a propósito: si alguien registra un mail ajeno y nunca abre el enlace, el
//    dueño de ese mail se registra igual. Al confirmar se vuelve a controlar que el mail, el DNI y el CUIT sigan libres.
//  - La contraseña se guarda ya hasheada (bcrypt) y del token solo el hash (sha256), igual que verificaciones_mail.
//  - Los datos fiscales y bancarios de un propietario o administrador van en JSON: se pasan tal cual a DatosFiscales.
//  - Los vencidos los borra la tarea diaria (scripts/borrar-registros-vencidos.php).
final class CrearTablaRegistrosPendientes extends AbstractMigration
{
    //Crea la tabla; Phinx sabe deshacerla sola (rollback) porque usa change()
    public function change(): void
    {
        $this->table('registros_pendientes')
            ->addColumn('mail', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 70, 'null' => false])
            ->addColumn('dni', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('contrasenia', 'string', ['limit' => 255, 'null' => false])              //el hash de bcrypt
            ->addColumn('rol', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('datos_fiscales', 'json', ['null' => true])                              //solo propietario y administrador
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])                  //sha256 en hexadecimal
            ->addColumn('expira_en', 'datetime', ['null' => false])
            ->addColumn('enviado_en', 'datetime', ['null' => false])                             //el último mail, para limitar el reenvío
            ->addColumn('creado_en', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['mail'], ['unique' => true])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['expira_en'])
            ->create();
    }
}
