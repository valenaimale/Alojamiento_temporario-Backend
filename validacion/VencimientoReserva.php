<?php

namespace App\Validacion;

use App\Database\Conexion;

//Cuánto tiempo guarda sus noches una reserva pendiente mientras el huésped paga la seña (L2-28).
//Al reservar se anota bloqueo_expira_en = ahora + minutos_bloqueo_reserva. Pasado ese momento, sin pagar,
//la reserva deja de ocupar sus noches aunque en la base todavía diga 'pendiente': las consultas usan
//ocupaNoches() / pendienteVigente(), y la API la muestra como 'expirada' (estadoVisible()).
//Recién se anota 'expirada' en la base cuando alguien la toca (pagar, cancelar): así no hace falta un cron.
//bloqueo_expira_en NULL = sin vencimiento (las pendientes de antes de los pagos).
//Las fechas van en UTC: se comparan con UTC_TIMESTAMP(), que no depende de la zona horaria de la base.
class VencimientoReserva
{
    private const MINUTOS_POR_DEFECTO = 15;

    //Condición SQL: la reserva es pendiente y su plazo para pagar no venció. $alias: el de la tabla reservas ("r").
    public static function pendienteVigente(string $alias = ''): string
    {
        $a = $alias === '' ? '' : "$alias.";
        return "({$a}estado = 'pendiente' AND ({$a}bloqueo_expira_en IS NULL OR {$a}bloqueo_expira_en > UTC_TIMESTAMP()))";
    }

    //Condición SQL: la reserva ocupa sus noches (pendiente vigente, confirmada o finalizada)
    public static function ocupaNoches(string $alias = ''): string
    {
        $a = $alias === '' ? '' : "$alias.";
        return "({$a}estado IN ('confirmada', 'finalizada') OR " . self::pendienteVigente($alias) . ')';
    }

    //Si a una pendiente ya se le pasó el plazo para pagar la seña
    public static function vencida(string $estado, ?string $expiraEn): bool
    {
        return $estado === 'pendiente' && $expiraEn !== null && $expiraEn <= self::ahora();//'AAAA-MM-DD HH:MM:SS' se compara como texto
    }

    //El estado como lo ve el usuario: una pendiente vencida ya es 'expirada'
    public static function estadoVisible(string $estado, ?string $expiraEn): string
    {
        return self::vencida($estado, $expiraEn) ? 'expirada' : $estado;
    }

    //Hasta cuándo se puede pagar la seña, en ISO 8601 UTC ("2026-11-05T20:15:00Z"), o null si no corresponde
    public static function venceEnIso(string $estado, ?string $expiraEn): ?string
    {
        if ($estado !== 'pendiente' || $expiraEn === null || self::vencida($estado, $expiraEn)) {
            return null;
        }
        return str_replace(' ', 'T', $expiraEn) . 'Z';
    }

    //El vencimiento de una reserva que se crea ahora, en UTC ('AAAA-MM-DD HH:MM:SS')
    public static function vencimientoDesdeAhora(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . self::minutosParaPagar() . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    //"Ahora" en UTC, como se guarda en la base
    public static function ahora(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    //"2026-11-05 20:15:00" (UTC, como en la base) → "17:15" (hora de Argentina, para los mensajes)
    public static function horaArgentina(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))
            ->format('H:i');
    }

    //El parámetro minutos_bloqueo_reserva (lo administra el backoffice)
    private static function minutosParaPagar(): int
    {
        $consulta = Conexion::obtener()->prepare("SELECT valor FROM parametros WHERE clave = 'minutos_bloqueo_reserva'");
        $consulta->execute();
        $minutos = filter_var($consulta->fetchColumn(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $minutos === false ? self::MINUTOS_POR_DEFECTO : $minutos;
    }
}
