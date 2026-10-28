<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 3: más motivos para bloquear fechas (US3.3, decidido el 28/10). El motivo es solo para el propietario
//(y su administrador), para recordar por qué bloqueó esas noches: el huésped nunca lo ve.
//Agrega 'uso_personal' (no quiere recibir reservas en esas fechas) y 'otro' (el motivo lo escribe el propietario).
//Con 'otro', el detalle es obligatorio: lo garantiza un CHECK.
//Usa up()/down() con SQL porque cambia un enum y agrega un CHECK.
final class AgregarMotivosDeBloqueo extends AbstractMigration
{
    //Agrega los dos motivos nuevos y el CHECK del detalle (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE bloqueos_calendario
             MODIFY motivo ENUM('reserva_externa', 'mantenimiento', 'danio', 'uso_personal', 'otro') NOT NULL"
        );
        $this->execute(
            "ALTER TABLE bloqueos_calendario ADD CONSTRAINT chk_bloqueos_calendario_otro_con_detalle
             CHECK (motivo <> 'otro' OR (detalle IS NOT NULL AND TRIM(detalle) <> ''))"
        );
    }

    //Vuelve a los 3 motivos originales (vendor/bin/phinx rollback).
    //OJO: si hay bloqueos con 'uso_personal' u 'otro', MySQL rechaza el cambio: hay que cambiarlos o borrarlos antes.
    public function down(): void
    {
        $this->execute('ALTER TABLE bloqueos_calendario DROP CHECK chk_bloqueos_calendario_otro_con_detalle');
        $this->execute(
            "ALTER TABLE bloqueos_calendario
             MODIFY motivo ENUM('reserva_externa', 'mantenimiento', 'danio') NOT NULL"
        );
    }
}
