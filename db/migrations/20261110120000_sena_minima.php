<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Pagos reales (decisión #52 de docs/pagos-decisiones.md): la seña de una unidad es de al menos el 1 %.
//El contrato PasarelaPago no acepta pagarSaldo si antes no se pagó la seña (error SinSena), así que una reserva
//con 0 % de seña nunca se podría pagar con ETH. Antes una unidad podía tener 0 % y la reserva nacía confirmada.
//
//Las unidades que tengan menos del 1 % pasan al 1 %: es el valor válido más cercano al que había elegido el
//propietario. Las reservas ya hechas no se tocan (cada una guarda su propia copia de la seña).
//Usa up()/down() con SQL porque cambia un CHECK.
final class SenaMinima extends AbstractMigration
{
    //Sube a 1 % las señas menores y cambia el CHECK para que la seña vaya de 1 a 100 (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->execute('UPDATE unidades SET porcentaje_sena = 1 WHERE porcentaje_sena < 1');
        $this->execute('ALTER TABLE unidades DROP CHECK chk_unidades_sena_en_rango');
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_sena_en_rango
             CHECK (porcentaje_sena >= 1 AND porcentaje_sena <= 100)'
        );
    }

    //Vuelve a permitir una seña de 0 % (vendor/bin/phinx rollback). Las unidades que se subieron al 1 % quedan así.
    public function down(): void
    {
        $this->execute('ALTER TABLE unidades DROP CHECK chk_unidades_sena_en_rango');
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_sena_en_rango
             CHECK (porcentaje_sena >= 0 AND porcentaje_sena <= 100)'
        );
    }
}
