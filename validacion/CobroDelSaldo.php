<?php

namespace App\Validacion;

use App\Database\Conexion;

//A quién hay que recordarle que pague el saldo de su reserva, y cuántas veces (RF-25).
//
//Cómo funciona: cuando el huésped paga la seña, la reserva queda confirmada y le queda el saldo.
//Ese saldo se cobra unos días antes del ingreso (el parámetro dias_cobro_saldo). Desde ese día, y mientras
//no pague, se le manda un recordatorio cada horas_entre_recordatorios, hasta max_recordatorios_cobro.
//Los tres parámetros los administra el backoffice.
//
//A diferencia del diseño original con tarjeta, la plataforma no puede cobrar sola: el huésped firma la
//transacción desde su billetera. Por eso lo que se cuenta no son reintentos de cobro sino recordatorios.
//
//No manda los mails: dice a quién hay que mandárselos (mail/RecordatorioSaldo.php los manda, y
//scripts/enviar-recordatorios.php los junta). Así la decisión de a quién avisarle se puede probar sola.
class CobroDelSaldo
{
    private const MAX_RECORDATORIOS_POR_DEFECTO = 3;
    private const HORAS_ENTRE_RECORDATORIOS_POR_DEFECTO = 24;
    private const DIAS_COBRO_SALDO_POR_DEFECTO = 5;

    //Las reservas a las que hoy les toca un recordatorio del saldo.
    //De cada una devuelve lo que necesita el mail: el huésped, la unidad, las fechas, cuánto debe y qué
    //número de recordatorio es (1, 2 o 3), más 'ultimo', que dice si es el último que se le va a mandar.
    //Ordenadas por fecha de ingreso: primero el que tiene la estadía más cerca.
    public static function pendientes(): array
    {
        $maximo = self::parametro('max_recordatorios_cobro', self::MAX_RECORDATORIOS_POR_DEFECTO);
        $horas = self::parametro('horas_entre_recordatorios', self::HORAS_ENTRE_RECORDATORIOS_POR_DEFECTO);
        $diasCobro = self::parametro('dias_cobro_saldo', self::DIAS_COBRO_SALDO_POR_DEFECTO);

        //Candidatas: reservas confirmadas, con el ingreso todavía por delante y sin el huésped adentro.
        //Si ya ingresó (tiene estadía), el saldo se resuelve en el mostrador, no por mail.
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.fecha_ingreso, r.fecha_egreso, r.importe_total, r.creado_en,
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
            $debe = self::restar((string) $reserva['importe_total'], (string) $reserva['cobrado']);
            $enviados = (int) $reserva['enviados'];

            if ($debe === null) {
                continue;   //ya pagó todo
            }
            if ($enviados >= $maximo) {
                continue;   //se le avisó todas las veces que corresponde; de acá en más es una deuda (RN-16)
            }
            if (self::fechaDeCobro($reserva, $diasCobro) > $hoy) {
                continue;   //todavía no es momento de pedirle el saldo
            }
            if ($reserva['ultimo_envio'] !== null && self::horasDesde((string) $reserva['ultimo_envio'], $ahora) < $horas) {
                continue;   //se le mandó uno hace poco: no se lo atosiga
            }

            $pendientes[] = [
                'reserva_id' => (int) $reserva['id'],
                'numero'     => $enviados + 1,
                'ultimo'     => $enviados + 1 >= $maximo,
                'debe'       => $debe,
                'ingreso'    => $reserva['fecha_ingreso'],
                'egreso'     => $reserva['fecha_egreso'],
                'unidad'     => $reserva['unidad'],
                'huesped'    => ['id' => (int) $reserva['huesped_id'], 'nombre' => $reserva['huesped'], 'mail' => $reserva['mail']],
            ];
        }
        return $pendientes;
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

    //El día en que se le empieza a pedir el saldo: dias_cobro_saldo antes del ingreso, o el día en que reservó
    //si reservó con menos anticipación (no tiene sentido pedírselo antes de que exista la reserva).
    //Cuando esté la columna reservas.fecha_cobro (Rol 2), esto se lee de ahí y deja de calcularse.
    private static function fechaDeCobro(array $reserva, int $diasCobro): string
    {
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
