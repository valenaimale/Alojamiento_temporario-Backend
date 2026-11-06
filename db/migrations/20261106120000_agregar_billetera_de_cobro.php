<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Entrega del 80 % (pagos con blockchain): la billetera donde cobran propietarios y administradores.
//Cuando el huésped ingresa, el back llama a liquidar() del contrato PasarelaPago, que manda el ETH retenido
//a la billetera del propietario, a la del administrador (su comisión) y a la de la plataforma.
//Es la dirección PÚBLICA (0x + 40 hexadecimales, como pagos.billetera), nunca una clave privada.
//
//La cuenta bancaria (cbu, alias, titular_cuenta) se queda: sirve para cobrar en pesos, y la billetera, en ETH.
//
//En la base la columna admite NULL solo por los propietarios y administradores que ya existían, que no
//tienen billetera y no se les puede inventar una: la cargan desde su perfil (POST /datos-fiscales/billetera).
//Para los nuevos es obligatoria: la pide DatosFiscales::validar() en el registro y en "hacerme propietario".
//Mientras no la carguen, sus liquidaciones quedan pendientes (el ETH sigue seguro en el contrato).
//
//Usa up()/down() en lugar de change() porque agrega CHECKs con SQL.
final class AgregarBilleteraDeCobro extends AbstractMigration
{
    //Agrega la columna billetera, con el control de su formato, a propietarios y administradores (vendor/bin/phinx migrate)
    public function up(): void
    {
        foreach (['propietarios', 'administradores'] as $tabla) {
            $this->table($tabla)
                ->addColumn('billetera', 'char', ['limit' => 42, 'null' => true, 'after' => 'titular_cuenta'])
                ->update();

            //la dirección 0x000…000 no es de nadie: lo que se le mande se pierde
            $this->execute(
                "ALTER TABLE {$tabla} ADD CONSTRAINT chk_{$tabla}_billetera
                 CHECK (billetera IS NULL OR (billetera REGEXP '^0x[0-9a-fA-F]{40}$' AND billetera <> CONCAT('0x', REPEAT('0', 40))))"
            );
        }
    }

    //Deshace los cambios: borra la columna de las dos tablas (vendor/bin/phinx rollback)
    public function down(): void
    {
        foreach (['propietarios', 'administradores'] as $tabla) {
            $this->execute("ALTER TABLE {$tabla} DROP CHECK chk_{$tabla}_billetera");
            $this->table($tabla)->removeColumn('billetera')->update();
        }
    }
}
