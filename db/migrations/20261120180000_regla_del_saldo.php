<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Regla del saldo (decisión #75 de docs/pagos-decisiones.md, plan de la entrega del 28/11).
//Los recordatorios del saldo dejan de salir "cada horas_entre_recordatorios, hasta max_recordatorios_cobro": ahora salen
//en días fijos, 3 días antes del último día para pagar, el día anterior y el mismo día (CobroDelSaldo::AVISOS).
//Esos dos parámetros ya no los lee nadie, así que se borran para que nadie crea que cambiándolos cambia algo.
//dias_cobro_saldo sigue: es la fecha de cobro, el último día para pagar y el corte del pago total al reservar.
//No toca reservas ni recordatorios_saldo: los recordatorios ya mandados quedan como historial.
final class ReglaDelSaldo extends AbstractMigration
{
    private const VIEJOS = [
        ['clave' => 'max_recordatorios_cobro', 'valor' => '3', 'descripcion' => 'Recordatorios de pago antes de que quede la deuda'],
        ['clave' => 'horas_entre_recordatorios', 'valor' => '24', 'descripcion' => 'Horas entre un recordatorio de pago y el siguiente'],
    ];

    //Borra los dos parámetros (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->execute("DELETE FROM parametros WHERE clave IN ('max_recordatorios_cobro', 'horas_entre_recordatorios')");
        $this->execute(
            "UPDATE parametros SET descripcion = 'Días antes del ingreso en que vence el saldo; reservando con menos se paga el total'
             WHERE clave = 'dias_cobro_saldo'"
        );
    }

    //Los vuelve a poner como estaban (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->table('parametros')->insert(self::VIEJOS)->saveData();
        $this->execute(
            "UPDATE parametros SET descripcion = 'Cuántos días antes del ingreso se pide el saldo' WHERE clave = 'dias_cobro_saldo'"
        );
    }
}
