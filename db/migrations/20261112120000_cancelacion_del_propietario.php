<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Cancelación del propietario y excepción a la retención (L2-19, decisiones #62 a #68 de docs/pagos-decisiones.md).
//
//En reservas:
//  - cancelada_en, cancelada_por y motivo_cancelacion: quién canceló (el huésped, el propietario o la plataforma por el
//    saldo vencido), cuándo y, si fue el propietario, por qué. Desde cancelada_en corren las 48 hs de la excepción.
//    Las canceladas de antes quedan en NULL: su plazo ya pasó.
//  - excepcion y excepcion_en: lo que el propietario decidió devolverle al huésped de lo retenido, en pesos, y cuándo.
//    Sale de la parte del propietario en la liquidación de la cancelada.
//En pagos: el tipo 'excepcion', el pago al huésped de esa excepción (sale del contrato con liquidar).
//En liquidaciones: la columna excepcion, y el CHECK del reparto la descuenta del importe del propietario.
//
//Usa up()/down() con SQL porque cambia un ENUM y unos CHECK.
final class CancelacionDelPropietario extends AbstractMigration
{
    //Agrega las columnas, el tipo de pago y los CHECK (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('reservas')
            ->addColumn('cancelada_en', 'datetime', ['null' => true])
            ->addColumn('cancelada_por', 'enum', ['values' => ['huesped', 'propietario', 'plataforma'], 'null' => true])
            ->addColumn('motivo_cancelacion', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('excepcion', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true])
            ->addColumn('excepcion_en', 'datetime', ['null' => true])
            ->update();
        $this->execute(
            "ALTER TABLE reservas
             ADD CONSTRAINT chk_reservas_excepcion CHECK (excepcion IS NULL OR (excepcion > 0 AND excepcion_en IS NOT NULL)),
             ADD CONSTRAINT chk_reservas_motivo_cancelacion CHECK (cancelada_por <> 'propietario' OR motivo_cancelacion IS NOT NULL)"
        );

        $this->execute(
            "ALTER TABLE pagos MODIFY tipo ENUM('sena', 'saldo', 'devolucion', 'liquidacion', 'excepcion') NOT NULL"
        );

        $this->table('liquidaciones')
            ->addColumn('excepcion', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => 0, 'after' => 'comision_administrador'])
            ->update();
        $this->execute('ALTER TABLE liquidaciones DROP CHECK chk_liquidaciones_reparto');
        $this->execute(
            'ALTER TABLE liquidaciones
             ADD CONSTRAINT chk_liquidaciones_reparto
             CHECK (importe_propietario = importe_cobrado - comision_plataforma - comision_administrador - excepcion),
             ADD CONSTRAINT chk_liquidaciones_excepcion CHECK (excepcion >= 0)'
        );
    }

    //Deshace los cambios (vendor/bin/phinx rollback). Falla si ya hay pagos de tipo 'excepcion': no se borran pagos.
    public function down(): void
    {
        $this->execute('ALTER TABLE liquidaciones DROP CHECK chk_liquidaciones_excepcion');
        $this->execute('ALTER TABLE liquidaciones DROP CHECK chk_liquidaciones_reparto');
        $this->execute(
            'ALTER TABLE liquidaciones
             ADD CONSTRAINT chk_liquidaciones_reparto
             CHECK (importe_propietario = importe_cobrado - comision_plataforma - comision_administrador)'
        );
        $this->table('liquidaciones')->removeColumn('excepcion')->update();

        $this->execute("ALTER TABLE pagos MODIFY tipo ENUM('sena', 'saldo', 'devolucion', 'liquidacion') NOT NULL");

        $this->execute('ALTER TABLE reservas DROP CHECK chk_reservas_motivo_cancelacion');
        $this->execute('ALTER TABLE reservas DROP CHECK chk_reservas_excepcion');
        $this->table('reservas')
            ->removeColumn('excepcion_en')->removeColumn('excepcion')->removeColumn('motivo_cancelacion')
            ->removeColumn('cancelada_por')->removeColumn('cancelada_en')
            ->update();
    }
}
