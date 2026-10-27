<?php

namespace App\Validacion;

//Fechas de las unidades: temporadas, precios por noche, bloqueos y búsquedas.
//Todas viajan como texto "AAAA-MM-DD" (las fechas son noches: no tienen hora).
class ValidadorFechas
{
    //Las fechas del negocio son las de Argentina. El servidor trabaja en UTC: a las 22 h de Argentina,
    //en UTC ya es el día siguiente, y "de hoy en adelante" dejaría afuera la noche de hoy.
    private const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';

    //"2026-10-05" → la fecha. null si no es una fecha real con ese formato exacto.
    //createFromFormat sola acepta "2026-02-30" (lo pasa al 2 de marzo): por eso se compara con el texto original.
    public static function fecha(string $texto): ?\DateTimeImmutable
    {
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $texto, new \DateTimeZone(self::ZONA_HORARIA));//! = sin hora (00:00)
        if ($fecha === false || $fecha->format('Y-m-d') !== $texto) {
            return null;
        }
        return $fecha;
    }

    //La fecha de hoy en Argentina, sin hora
    public static function hoy(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(self::ZONA_HORARIA));
    }

    //Cuántas noches hay entre el ingreso y el egreso: del 5/10 al 10/10 son 5 (la fecha de egreso no es una noche)
    public static function noches(\DateTimeImmutable $ingreso, \DateTimeImmutable $egreso): int
    {
        return (int) $ingreso->diff($egreso)->format('%r%a');//%r: con signo, así un egreso anterior al ingreso da negativo
    }
}
