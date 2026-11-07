<?php

namespace App\Validacion;

use App\Database\Conexion;

//A quién hay que recordarle que pague el saldo de su reserva, y cuántas veces (RF-25).
//
//Cómo funciona: cuando el huésped paga la seña, la reserva queda confirmada y le queda el saldo.
//Ese saldo se cobra desde reservas.fecha_cobro (dias_cobro_saldo antes del ingreso). Desde ese día, y mientras
//no pague, se le manda un recordatorio cada horas_entre_recordatorios, hasta max_recordatorios_cobro.
//Los tres parámetros los administra el backoffice.
//
//A diferencia del diseño original con tarjeta, la plataforma no puede cobrar sola: el huésped firma la
//transacción desde su billetera. Por eso lo que se cuenta no son reintentos de cobro sino recordatorios,
//y por eso el saldo impago tiene una consecuencia: pasado el plazo de la decisión #23 de
//docs/pagos-decisiones.md, la reserva se cancela sola con la retención del tramo de menos de 15 días.
//Ese plazo es el que informan los recordatorios, y lo calcula limiteDePago().
//
//No manda los mails: dice a quién hay que mandárselos (mail/RecordatorioSaldo.php los manda, y
//scripts/enviar-recordatorios.php los junta). Así la decisión de a quién avisarle se puede probar sola.
class CobroDelSaldo
{
    private const MAX_RECORDATORIOS_POR_DEFECTO = 3;
    private const HORAS_ENTRE_RECORDATORIOS_POR_DEFECTO = 24;
    private const DIAS_COBRO_SALDO_POR_DEFECTO = 5;
    private const DIAS_VENCIMIENTO_SALDO_POR_DEFECTO = 3;

    //Las reservas a las que hoy les toca un recordatorio del saldo.
    //De cada una devuelve lo que necesita el mail: el huésped, la unidad, las fechas, cuánto debe, hasta cuándo
    //puede pagarlo ('limite') y qué número de recordatorio es (1, 2 o 3), más 'ultimo', que dice si es el último
    //que se le va a mandar. Ordenadas por fecha de ingreso: primero el que tiene la estadía más cerca.
    public static function pendientes(): array
    {
        $maximo = self::parametro('max_recordatorios_cobro', self::MAX_RECORDATORIOS_POR_DEFECTO);
        $horas = self::parametro('horas_entre_recordatorios', self::HORAS_ENTRE_RECORDATORIOS_POR_DEFECTO);
        $diasCobro = self::parametro('dias_cobro_saldo', self::DIAS_COBRO_SALDO_POR_DEFECTO);
        $diasVencimiento = self::parametro('dias_vencimiento_saldo', self::DIAS_VENCIMIENTO_SALDO_POR_DEFECTO);

        //Candidatas: reservas confirmadas, con el ingreso todavía por delante y sin el huésped adentro.
        //Las canceladas, las expiradas y las finalizadas no entran por el estado: a una reserva que ya no sigue
        //en pie no se le pide plata, y la cancelación automática del saldo vencido (#23) la deja 'cancelada'.
        //Si ya ingresó (tiene estadía), el saldo ya está pagado: sin el saldo no se registra el ingreso (#25).
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.fecha_ingreso, r.fecha_egreso, r.importe_total, r.creado_en,
                    r.monto_faltante, r.fecha_cobro,
                    u.nombre AS unidad,
                    h.id AS huesped_id, h.nombre AS huesped, h.mail,
                    (SELECT COALESCE(SUM(p.monto_pesos), 0) FROM pagos p
                      WHERE p.reserva_id = r.id AND p.estado = 'confirmado' AND p.tipo IN ('sena', 'saldo')) AS cobrado,
                    (SELECT COUNT(*) FROM recordatorios_saldo s WHERE s.reserva_id = r.id) AS enviados,
                    (SELECT MAX(s.enviado_en) FROM recordatorios_saldo s WHERE s.reserva_id = r.id) AS ultimo_envio
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             LEFT JOIN estadias e ON e.reserva_id = r.id
             WHERE r.estado = 'confirmada' AND e.reserva_id IS NULL AND r.fecha_ingreso >= ?
             ORDER BY r.fecha_ingreso, r.id"
        );
        $consulta->execute([ValidadorFechas::hoy()->format('Y-m-d')]);

        $hoy = ValidadorFechas::hoy()->format('Y-m-d');
        $ahora = self::ahora();
        $pendientes = [];
        foreach ($consulta->fetchAll() as $reserva) {
            $debe = self::debe($reserva);
            $enviados = (int) $reserva['enviados'];
            $fechaCobro = self::fechaDeCobro($reserva, $diasCobro);
            $limite = self::limiteDePago($fechaCobro, (string) $reserva['fecha_ingreso'], $diasVencimiento);

            if ($debe === null) {
                continue;   //ya pagó todo
            }
            if ($enviados >= $maximo) {
                continue;   //se le avisó todas las veces que corresponde; de acá en más es una deuda (RN-16)
            }
            if ($fechaCobro > $hoy) {
                continue;   //todavía no es momento de pedirle el saldo
            }
            if ($limite < $hoy) {
                continue;   //se le pasó el plazo: lo que corresponde es cancelarla (#23), no mandarle otro mail
            }
            if ($reserva['ultimo_envio'] !== null && self::horasDesde((string) $reserva['ultimo_envio'], $ahora) < $horas) {
                continue;   //se le mandó uno hace poco: no se lo atosiga
            }

            $pendientes[] = [
                'reserva_id'  => (int) $reserva['id'],
                'numero'      => $enviados + 1,
                'ultimo'      => $enviados + 1 >= $maximo,
                'debe'        => $debe,
                'ingreso'     => $reserva['fecha_ingreso'],
                'egreso'      => $reserva['fecha_egreso'],
                'fecha_cobro' => $fechaCobro,
                'limite'      => $limite,
                'vence_hoy'   => $limite === $hoy,
                'unidad'      => $reserva['unidad'],
                'huesped'     => ['id' => (int) $reserva['huesped_id'], 'nombre' => $reserva['huesped'], 'mail' => $reserva['mail']],
            ];
        }
        return $pendientes;
    }

    //El último día en que el huésped puede pagar el saldo (decisión #23 de docs/pagos-decisiones.md):
    //dias_vencimiento_saldo días después de la fecha de cobro, o el día del ingreso si llega antes (pasa cuando
    //se reservó con poca anticipación). Ese día lo puede pagar al llegar, porque sin el saldo no se registra
    //el ingreso (#25). Pasado el plazo, la reserva se cancela sola con el tramo de menos de 15 días.
    //
    //Es público porque la cancelación automática (el script con cron del Rol 2) tiene que usar el MISMO plazo
    //que anuncian los recordatorios: si cada uno lo calculara por su cuenta, el mail diría una fecha y la
    //reserva se cancelaría en otra.
    public static function limiteDePago(string $fechaCobro, string $fechaIngreso, ?int $dias = null): string
    {
        $dias ??= self::parametro('dias_vencimiento_saldo', self::DIAS_VENCIMIENTO_SALDO_POR_DEFECTO);
        $vencimiento = (new \DateTimeImmutable($fechaCobro))->modify("+$dias days")->format('Y-m-d');
        //nunca antes de la fecha de cobro: si el ingreso fuera anterior, igual se le da ese día para pagar
        return max($fechaCobro, min($vencimiento, $fechaIngreso));
    }

    //Anota que se mandó el recordatorio. Devuelve false si otro proceso ya lo había anotado,
    //y entonces el mail no se manda: el índice único es el que garantiza que no se mande dos veces.
    public static function anotar(int $reservaId, int $numero): bool
    {
        try {
            Conexion::obtener()
                ->prepare('INSERT INTO recordatorios_saldo (reserva_id, numero, enviado_en) VALUES (?, ?, ?)')
                ->execute([$reservaId, $numero, self::ahora()]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {   //clave duplicada
                return false;
            }
            throw $e;
        }
        return true;
    }

    //Cuánto falta pagar de la reserva, como texto, o null si ya está todo cobrado.
    //Lo autoritativo es reservas.monto_faltante, que el Rol 2 deja en 0 cuando se paga el saldo (#20).
    //Las reservas viejas que no lo tengan se resuelven como antes, restando lo cobrado del total (#26).
    private static function debe(array $reserva): ?string
    {
        if ($reserva['monto_faltante'] !== null) {
            return self::restar((string) $reserva['monto_faltante'], '0');
        }
        return self::restar((string) $reserva['importe_total'], (string) $reserva['cobrado']);
    }

    //El día en que se le empieza a pedir el saldo: reservas.fecha_cobro, que guarda el Rol 2 al reservar.
    //Las reservas viejas que no lo tengan se calculan como antes: dias_cobro_saldo antes del ingreso, o el día
    //en que reservó si reservó con menos anticipación (no tiene sentido pedírselo antes de que exista la reserva).
    private static function fechaDeCobro(array $reserva, int $diasCobro): string
    {
        if ($reserva['fecha_cobro'] !== null) {
            return (string) $reserva['fecha_cobro'];
        }
        $cobro = (new \DateTimeImmutable($reserva['fecha_ingreso']))->modify("-$diasCobro days")->format('Y-m-d');
        $reservadaEn = substr((string) $reserva['creado_en'], 0, 10);
        return max($cobro, $reservadaEn);
    }

    //Cuánto falta pagar, como texto, o null si ya está todo cobrado. En centavos enteros, sin coma flotante.
    private static function restar(string $total, string $cobrado): ?string
    {
        $falta = self::aCentavos($total) - self::aCentavos($cobrado);
        return $falta > 0 ? sprintf('%d.%02d', intdiv($falta, 100), $falta % 100) : null;
    }

    //"400000.00" → 40000000 centavos
    private static function aCentavos(string $monto): int
    {
        [$entera, $decimal] = array_pad(explode('.', trim($monto), 2), 2, '0');
        return (int) $entera * 100 + (int) substr(str_pad($decimal, 2, '0'), 0, 2);
    }

    //Horas entre dos momentos guardados como "AAAA-MM-DD HH:MM:SS" en UTC
    private static function horasDesde(string $desde, string $hasta): float
    {
        return (strtotime("$hasta UTC") - strtotime("$desde UTC")) / 3600;
    }

    //Un parámetro entero de la plataforma; si falta o no sirve, el valor por defecto
    private static function parametro(string $clave, int $porDefecto): int
    {
        $consulta = Conexion::obtener()->prepare('SELECT valor FROM parametros WHERE clave = ?');
        $consulta->execute([$clave]);
        $valor = filter_var($consulta->fetchColumn(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $valor === false ? $porDefecto : $valor;
    }

    //"Ahora" en UTC, como se guardan los momentos en la base
    private static function ahora(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
