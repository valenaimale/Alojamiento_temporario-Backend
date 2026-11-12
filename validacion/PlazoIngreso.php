<?php

namespace App\Validacion;

//Hasta cuándo se puede registrar el ingreso de una reserva (no-show, decisión #67 de docs/pagos-decisiones.md).
//El día del ingreso, a la hora de check-in de la unidad (en hora de Argentina), empiezan a correr 24 hs. Si nadie
//registró el ingreso en ese plazo, la reserva pasa a 'no_show' (lo marca scripts/marcar-no-shows.php).
//La usan el registro del ingreso (EstadiasController), la agenda del operador y el no-show: así los tres cuentan igual.
//Los momentos van en UTC ('AAAA-MM-DD HH:MM:SS'), como se guardan en la base.
class PlazoIngreso
{
    public const HORAS = 24;
    private const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';

    //El límite para registrar el ingreso, en UTC. $fechaIngreso: 'AAAA-MM-DD'; $horaCheckIn: 'HH:MM:SS' o 'HH:MM'.
    //Ejemplo: ingreso 2026-12-27 con check-in 14:00 (Argentina, UTC−3) → '2026-12-28 17:00:00'.
    public static function limite(string $fechaIngreso, string $horaCheckIn): string
    {
        return (new \DateTimeImmutable("$fechaIngreso $horaCheckIn", new \DateTimeZone(self::ZONA_HORARIA)))
            ->modify('+' . self::HORAS . ' hours')
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    //Si ya se pasó el plazo. $ahora: en UTC; por defecto, el momento actual.
    public static function vencido(string $fechaIngreso, string $horaCheckIn, ?string $ahora = null): bool
    {
        return ($ahora ?? VencimientoReserva::ahora()) >= self::limite($fechaIngreso, $horaCheckIn);//se compara como texto
    }

    //El límite en ISO 8601 UTC ("2026-12-28T17:00:00Z"), para las respuestas JSON
    public static function limiteIso(string $fechaIngreso, string $horaCheckIn): string
    {
        return str_replace(' ', 'T', self::limite($fechaIngreso, $horaCheckIn)) . 'Z';
    }
}
