<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: propietarios y administradores tienen los mismos datos fiscales y bancarios (DER v2, punto 19).
//- propietarios: se agregan la cuenta bancaria (cbu, alias, titular_cuenta), donde cobra lo que se le liquida.
//- administradores: tabla nueva, una fila por cada usuario con rol 'administrador' (como propietarios),
//  con los mismos datos: donde cobra sus comisiones y cómo las factura.
//- En las dos: si cobra IVA, el CUIT, la razón social y el domicilio fiscal son obligatorios (lo garantiza un CHECK).
//Usa up()/down() en lugar de change() porque controla datos y agrega CHECK con SQL.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class AgregarDatosBancariosYAdministradores extends AbstractMigration
{
    public function up(): void
    {
        //Las cuentas que ya existen no tienen estos datos y no se pueden inventar: la migración no sigue.
        //Antes de migrar hay que pasarlas a huésped (son cuentas de prueba): ver el mensaje.
        $existentes = $this->fetchRow(
            "SELECT (SELECT COUNT(*) FROM propietarios) AS propietarios,
                    (SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador') AS administradores"
        );
        if ((int) $existentes['propietarios'] > 0 || (int) $existentes['administradores'] > 0) {
            throw new \RuntimeException(
                "Hay {$existentes['propietarios']} propietarios y {$existentes['administradores']} administradores "
                . "sin datos bancarios. Si son cuentas de prueba, pasalas a huésped con "
                . "'php scripts/pasar-a-huesped-sin-datos-bancarios.php' y volvé a migrar."
            );
        }

        $this->table('propietarios')
            ->addColumn('cbu', 'char', ['limit' => 22, 'null' => false, 'after' => 'domicilio_fiscal'])
            ->addColumn('alias', 'string', ['limit' => 20, 'null' => true, 'after' => 'cbu'])
            ->addColumn('titular_cuenta', 'string', ['limit' => 150, 'null' => false, 'after' => 'alias'])
            ->update();

        $this->table('administradores', ['id' => false, 'primary_key' => ['usuario_id']])
            ->addColumn('usuario_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('cobra_iva', 'boolean', ['null' => false])
            ->addColumn('cuit', 'string', ['limit' => 11, 'null' => true])
            ->addColumn('razon_social', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('domicilio_fiscal', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('cbu', 'char', ['limit' => 22, 'null' => false])
            ->addColumn('alias', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('titular_cuenta', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('fecha_alta', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['cuit'], ['unique' => true])
            ->addForeignKey('usuario_id', 'usuarios', 'id', ['delete' => 'CASCADE'])
            ->create();

        //Si cobra IVA, los tres datos fiscales tienen que estar. Si no cobra IVA, son opcionales.
        foreach (['propietarios', 'administradores'] as $tabla) {
            $this->execute(
                "ALTER TABLE {$tabla} ADD CONSTRAINT chk_{$tabla}_datos_iva
                 CHECK (cobra_iva = 0 OR (cuit IS NOT NULL AND razon_social IS NOT NULL AND domicilio_fiscal IS NOT NULL))"
            );
        }
    }

    public function down(): void
    {
        $this->table('administradores')->drop()->save();

        $this->execute('ALTER TABLE propietarios DROP CHECK chk_propietarios_datos_iva');
        $this->table('propietarios')
            ->removeColumn('cbu')
            ->removeColumn('alias')
            ->removeColumn('titular_cuenta')
            ->update();
    }
}
