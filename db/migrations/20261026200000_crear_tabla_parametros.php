<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: valores globales de la plataforma, para no escribirlos fijos en el código.
//Los administra el backoffice. Si un valor cambia, lo ya calculado no cambia:
//las unidades, las reservas y las liquidaciones guardan su propia copia.
//Usa up()/down() en lugar de change() porque carga datos.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaParametros extends AbstractMigration
{
    public function up(): void
    {
        $this->table('parametros', ['id' => false, 'primary_key' => ['clave']])
            ->addColumn('clave', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('valor', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('descripcion', 'string', ['limit' => 255, 'null' => true])
            ->create();

        //los valores del DER v2 (tabla parametros)
        $this->table('parametros')->insert([
            ['clave' => 'comision_plataforma', 'valor' => '7.5', 'descripcion' => '% que cobra la plataforma sobre cada estadía'],
            ['clave' => 'iva', 'valor' => '21', 'descripcion' => '% de IVA'],
            ['clave' => 'dias_cobro_saldo', 'valor' => '5', 'descripcion' => 'Cuántos días antes del ingreso se pide el saldo'],
            ['clave' => 'max_recordatorios_cobro', 'valor' => '3', 'descripcion' => 'Recordatorios de pago antes de que quede la deuda'],
            ['clave' => 'horas_entre_recordatorios', 'valor' => '24', 'descripcion' => 'Horas entre un recordatorio de pago y el siguiente'],
            ['clave' => 'minutos_bloqueo_reserva', 'valor' => '15', 'descripcion' => 'Minutos que se guardan las fechas mientras el huésped paga la seña'],
            ['clave' => 'horas_decision_excepcion', 'valor' => '48', 'descripcion' => 'Horas que tiene el propietario para decidir una excepción a la cancelación'],
            ['clave' => 'sena_defecto', 'valor' => '20', 'descripcion' => '% de seña que recibe una unidad nueva'],
            ['clave' => 'politica_defecto_mas_de_30', 'valor' => '30', 'descripcion' => '% que retiene una unidad nueva si se cancela más de 30 días antes del cobro'],
            ['clave' => 'politica_defecto_de_15_a_30', 'valor' => '50', 'descripcion' => '% que retiene una unidad nueva si se cancela entre 15 y 30 días antes del cobro'],
            ['clave' => 'politica_defecto_menos_de_15', 'valor' => '70', 'descripcion' => '% que retiene una unidad nueva si se cancela menos de 15 días antes del cobro'],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('parametros')->drop()->save();
    }
}
