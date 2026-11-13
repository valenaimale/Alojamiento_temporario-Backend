<?php

namespace App\Validacion;

use App\Database\Conexion;
use App\Mail\NoShowMail;

//El no-show: la reserva confirmada a la que nadie le registró el ingreso dentro del plazo (decisión #67 de
//docs/pagos-decisiones.md). Hay 24 hs desde el horario de check-in de la unidad (PlazoIngreso, la misma función que
//usan el registro del ingreso y la agenda del operador: así los tres cuentan igual). Pasado el plazo, la reserva
//pasa SOLA a 'no_show', con no_show_en. No hay una marca manual.
//Desde ahí se trata igual que una cancelación del huésped con todo pagado (#62):
//  - se retiene todo lo pagado (la seña sola, o la seña y el saldo);
//  - el propietario tiene 48 hs desde no_show_en para darle una excepción (ExcepcionDeCancelacion), sin obligación;
//  - las noches que quedaban se liberan solas, porque un 'no_show' no ocupa (VencimientoReserva::ocupaNoches);
//  - lo retenido se liquida (Liquidacion), cuando pasan las 48 hs o el propietario ya decidió.
//
//Lo corre scripts/marcar-no-shows.php, en las tareas frecuentes, antes de generar-liquidaciones.php.
//Es seguro de repetir: marcar() solo toca reservas confirmadas sin ingreso, y el UPDATE exige ese estado, así que
//una reserva ya marcada no se vuelve a marcar y no se le avisa dos veces.
class NoShow
{
    //Marca como 'no_show' todas las reservas confirmadas a las que se les pasó el plazo para registrar el ingreso,
    //y le avisa por mail al huésped y al propietario de cada una. Devuelve las reservas que marcó esta corrida,
    //como las filas que devuelve vencidas() (vacío si no había ninguna), para que el script las pueda listar.
    //$ahora: el momento con el que se compara el plazo y el que queda en no_show_en, en UTC ('AAAA-MM-DD HH:MM:SS');
    //por defecto, el momento actual. $avisar: false para no mandar los mails.
    public static function marcar(?string $ahora = null, bool $avisar = true): array
    {
        $ahora ??= VencimientoReserva::ahora();
        $marcadas = [];
        foreach (self::vencidas($ahora) as $reserva) {
            if (!self::anotar((int) $reserva['id'], $ahora)) {
                continue;//otro proceso la marcó, o alguien registró el ingreso entre la consulta y el UPDATE
            }
            $marcadas[] = $reserva;
            if ($avisar) {
                self::avisar($reserva);
            }
        }
        return $marcadas;
    }

    //Las reservas confirmadas sin ingreso a las que ya se les pasó el plazo, de la más vieja a la más nueva, con lo
    //que hace falta para avisar. El plazo se calcula en PHP con PlazoIngreso, no en SQL: es la misma cuenta que hace
    //el registro del ingreso (hora de check-in de la unidad, en hora de Argentina), y no se puede escribir dos veces.
    //La consulta trae solo las candidatas posibles (el día del ingreso ya empezó en Argentina) y PHP filtra el resto.
    //Una extensión nunca es candidata: no tiene ingreso propio, se registra en la reserva que extiende (#70).
    public static function vencidas(?string $ahora = null): array
    {
        $ahora ??= VencimientoReserva::ahora();
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.fecha_ingreso, r.fecha_egreso, u.hora_check_in, u.nombre AS unidad_nombre,
                    h.id AS huesped_id, h.nombre AS huesped_nombre, h.mail AS huesped_mail,
                    p.id AS propietario_id, p.nombre AS propietario_nombre, p.mail AS propietario_mail
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             JOIN usuarios p ON p.id = u.propietario_id
             WHERE r.estado = 'confirmada'
               AND r.extiende_reserva_id IS NULL
               AND r.fecha_ingreso <= ?
               AND NOT EXISTS (SELECT 1 FROM estadias e WHERE e.reserva_id = r.id AND e.ingreso_en IS NOT NULL)
             ORDER BY r.fecha_ingreso, r.id"
        );
        //el día del ingreso tiene que haber empezado en Argentina: antes de eso el plazo ni arrancó
        $consulta->execute([self::hoyEnArgentina($ahora)]);

        return array_values(array_filter(
            $consulta->fetchAll(),
            fn (array $r) => PlazoIngreso::vencido($r['fecha_ingreso'], $r['hora_check_in'], $ahora)
        ));
    }

    //Pasa una reserva a 'no_show' con su no_show_en. Devuelve si la marcó esta llamada: el UPDATE exige que siga
    //confirmada y sin ingreso, así que dos corridas a la vez marcan una sola vez y nadie pisa un ingreso recién
    //registrado. El CHECK chk_reservas_no_show de la base exige no_show_en, que se anota en el mismo UPDATE.
    private static function anotar(int $reservaId, string $ahora): bool
    {
        $consulta = Conexion::obtener()->prepare(
            "UPDATE reservas SET estado = 'no_show', no_show_en = ?
             WHERE id = ? AND estado = 'confirmada'
               AND NOT EXISTS (SELECT 1 FROM estadias e WHERE e.reserva_id = reservas.id AND e.ingreso_en IS NOT NULL)"
        );
        $consulta->execute([$ahora, $reservaId]);
        return $consulta->rowCount() === 1;
    }

    //Le avisa al huésped lo que se retuvo y al propietario que tiene 48 hs para dar una excepción (#64).
    //Los montos salen de la liquidación de lo retenido, que es la misma cuenta que va a ver el propietario.
    private static function avisar(array $reserva): void
    {
        $montos = Liquidacion::retenidoYMaximo((int) $reserva['id']) ?? ['retenido' => '0.00', 'maximo' => '0.00'];
        NoShowMail::alHuesped(
            ['id' => (int) $reserva['huesped_id'], 'nombre' => $reserva['huesped_nombre'], 'mail' => $reserva['huesped_mail']],
            $reserva['unidad_nombre'], $reserva['fecha_ingreso'], $reserva['fecha_egreso'], $montos['retenido']
        );
        NoShowMail::alPropietario(
            ['id' => (int) $reserva['propietario_id'], 'nombre' => $reserva['propietario_nombre'], 'mail' => $reserva['propietario_mail']],
            $reserva['unidad_nombre'], $reserva['huesped_nombre'], $reserva['fecha_ingreso'], $reserva['fecha_egreso'],
            $montos['retenido'], $montos['maximo']
        );
    }

    //El día de hoy en Argentina ('AAAA-MM-DD') a partir de un momento en UTC
    private static function hoyEnArgentina(string $ahora): string
    {
        return (new \DateTimeImmutable($ahora, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))
            ->format('Y-m-d');
    }
}
