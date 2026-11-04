<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Corrección del profesor (entrega 4): los % de la política de cancelación son sobre la SEÑA, no sobre el total.
//Antes cada retención era un % del total y por eso la seña no podía superar la retención más baja.
//Ahora son dos cosas distintas: la seña es un % del total y cada retención es un % de la seña.
//Esa regla deja de tener sentido: solo queda que las retenciones crezcan al acercarse la fecha.
//Los valores guardados no cambian (siguen entre 0 y 100 y en orden): cambia lo que significan.
//Usa up()/down() con SQL porque cambia CHECKs.
final class PoliticaSobreLaSena extends AbstractMigration
{
    //Cambia los dos CHECK de la política y la descripción de los valores por defecto (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->execute('ALTER TABLE unidades DROP CHECK chk_unidades_sena_y_politica');
        $this->execute('ALTER TABLE unidades DROP CHECK chk_unidades_porcentajes_en_rango');
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_sena_en_rango
             CHECK (porcentaje_sena >= 0 AND porcentaje_sena <= 100)'
        );
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_politica
             CHECK (retencion_mas_de_30 >= 0
                    AND retencion_mas_de_30 <= retencion_de_15_a_30
                    AND retencion_de_15_a_30 <= retencion_menos_de_15
                    AND retencion_menos_de_15 <= 100)'
        );
        $this->describirRetenciones('de la seña');
    }

    //Vuelve a la regla anterior (vendor/bin/phinx rollback).
    //OJO: si alguna unidad tiene una seña mayor que su retención de más de 30 días, MySQL rechaza el CHECK:
    //hay que corregir esas unidades antes.
    public function down(): void
    {
        $this->execute('ALTER TABLE unidades DROP CHECK chk_unidades_politica');
        $this->execute('ALTER TABLE unidades DROP CHECK chk_unidades_sena_en_rango');
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_porcentajes_en_rango
             CHECK (porcentaje_sena >= 0 AND retencion_menos_de_15 <= 100)'
        );
        $this->execute(
            'ALTER TABLE unidades ADD CONSTRAINT chk_unidades_sena_y_politica
             CHECK (porcentaje_sena <= retencion_mas_de_30
                    AND retencion_mas_de_30 <= retencion_de_15_a_30
                    AND retencion_de_15_a_30 <= retencion_menos_de_15)'
        );
        $this->describirRetenciones('del total');
    }

    //Actualiza la descripción de los 3 valores por defecto de la política ("% de la seña" o "% del total")
    private function describirRetenciones(string $base): void
    {
        $tramos = [
            'politica_defecto_mas_de_30'   => 'más de 30 días',
            'politica_defecto_de_15_a_30'  => 'entre 15 y 30 días',
            'politica_defecto_menos_de_15' => 'menos de 15 días',
        ];
        $consulta = $this->getAdapter()->getConnection()->prepare('UPDATE parametros SET descripcion = ? WHERE clave = ?');
        foreach ($tramos as $clave => $tramo) {
            $consulta->execute(["% $base que retiene una unidad nueva si se cancela $tramo antes del cobro", $clave]);
        }
    }
}
