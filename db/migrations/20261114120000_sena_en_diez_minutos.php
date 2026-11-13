<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Las reservas pendientes que vencen sin pagar la seña (pendientes del 11/11, punto 3):
//- El plazo para pagar la seña pasa de 15 a 10 minutos (parámetro minutos_bloqueo_reserva).
//- Las pendientes viejas sin vencimiento (bloqueo_expira_en NULL, de antes de los pagos) ocupaban sus noches para
//  siempre: pasan a 'expirada' y las noches se liberan. Las extensiones no se tocan (nacen siempre con vencimiento).
//
//down() solo vuelve el parámetro a 15: las reservas que se expiraron no vuelven a pendiente (ya no se sabe cuáles eran,
//y volverlas a pendiente sin vencimiento volvería a trabar esas noches).
final class SenaEnDiezMinutos extends AbstractMigration
{
    //Aplica el cambio del plazo y expira las pendientes sin vencimiento
    public function up(): void
    {
        $this->execute("UPDATE parametros SET valor = '10' WHERE clave = 'minutos_bloqueo_reserva'");
        $this->execute(
            "UPDATE reservas SET estado = 'expirada'
             WHERE estado = 'pendiente' AND bloqueo_expira_en IS NULL AND extiende_reserva_id IS NULL"
        );
    }

    //Vuelve el plazo a 15 minutos
    public function down(): void
    {
        $this->execute("UPDATE parametros SET valor = '15' WHERE clave = 'minutos_bloqueo_reserva'");
    }
}
