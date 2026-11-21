<?php

namespace App\Validacion;

use App\Database\Conexion;

//A quién hay que recordarle que pague el saldo de su reserva, y cuándo (RF-25 y decisión #75).
//
//Cómo funciona: cuando el huésped paga la seña, la reserva queda confirmada y le queda el saldo.
//El saldo se paga hasta el final del día de reservas.fecha_cobro (dias_cobro_saldo antes del ingreso), sin días de
//gracia (#75, reemplaza los 3 días de la #23). Se le recuerda tres veces, en días fijos contados hacia ese día:
//3 días antes, 1 día antes y el mismo día (AVISOS). Pasado ese día sin pagar, la reserva se cancela sola con la
//retención del tramo de menos de 15 días (scripts/cancelar-saldos-vencidos.php).
//Quien reserva con dias_cobro_saldo días o menos de anticipación paga todo al reservar (Estadia::senaAlReservar):
//no le queda saldo, así que no recibe recordatorios.
//
//A diferencia del diseño original con tarjeta, la plataforma no puede cobrar sola: el huésped firma la
//transacción desde su billetera. Por eso lo que se cuenta no son reintentos de cobro sino recordatorios.
//
//No manda los mails: dice a quién hay que mandárselos (mail/RecordatorioSaldo.php los manda, y
//scripts/enviar-recordatorios.php los junta). Así la decisión de a quién avisarle se puede probar sola.
class CobroDelSaldo
{
    //Cuántos días antes del último día para pagar sale cada recordatorio: el 1 a los 3 días, el 2 el día anterior
    //y el 3 el mismo día. El número de recordatorio es la posición + 1 (el índice único de recordatorios_saldo lo
    //usa para no mandar el mismo dos veces).
    public const AVISOS = [3, 1, 0];

    private const DIAS_COBRO_SALDO_POR_DEFECTO = 5;

    //Las reservas a las que hoy les toca un recordatorio del saldo.
    //De cada una devuelve lo que necesita el mail: el huésped, la unidad, las fechas, cuánto debe, hasta cuándo
    //puede pagarlo ('limite'), cuántos días le quedan ('dias_restantes') y qué recordatorio es ('numero', de 1 a 3),
    //más 'ultimo' y 'vence_hoy', que dicen si es el del mismo día. Ordenadas por fecha de ingreso.
    //En una reserva grupal, el plazo es el de la fecha de cobro más temprana del grupo (como en
    //ReservaGrupal::limiteDelSaldo) y sale un solo recordatorio para todas sus unidades (juntarGrupos).
    public static function pendientes(): array
    {
        //Candidatas: reservas confirmadas, con el ingreso todavía por delante y sin el huésped adentro.
        //Las canceladas, las expiradas y las finalizadas no entran por el estado: a una reserva que ya no sigue
        //en pie no se le pide plata. Si ya ingresó (tiene estadía), el saldo ya está pagado (#25).
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.fecha_ingreso, r.fecha_egreso, r.importe_total, r.creado_en,
                    r.monto_faltante, r.fecha_cobro, r.grupo_id,
                    u.nombre AS unidad,
                    h.id AS huesped_id, h.nombre AS huesped, h.mail,
                    (SELECT COALESCE(SUM(p.monto_pesos), 0) FROM pagos p
                      WHERE p.reserva_id = r.id AND p.estado = 'confirmado' AND p.tipo IN ('sena', 'saldo')) AS cobrado,
                    (SELECT COALESCE(MAX(s.numero), 0) FROM recordatorios_saldo s WHERE s.reserva_id = r.id) AS ultimo_numero
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             LEFT JOIN estadias e ON e.reserva_id = r.id
             WHERE r.estado = 'confirmada' AND e.reserva_id IS NULL AND r.fecha_ingreso >= ?
             ORDER BY r.fecha_ingreso, r.id"
        );
        $hoy = ValidadorFechas::hoy()->format('Y-m-d');
        $consulta->execute([$hoy]);
        $diasCobro = self::parametro('dias_cobro_saldo', self::DIAS_COBRO_SALDO_POR_DEFECTO);

        //Primero, las que deben y su plazo. En un grupo, el plazo es el más temprano de las unidades que deben:
        //así el mail del grupo sale en los mismos días en que después se cancelaría todo junto.
        $deudoras = [];
        $limiteDelGrupo = [];//grupo_id → el plazo más temprano
        foreach ($consulta->fetchAll() as $reserva) {
            $debe = self::debe($reserva);
            if ($debe === null) {
                continue;   //ya pagó todo (o pagó el total al reservar)
            }
            $fechaCobro = self::fechaDeCobro($reserva, $diasCobro);
            $reserva['debe'] = $debe;
            $reserva['fecha_cobro'] = $fechaCobro;
            $reserva['limite'] = self::limiteDePago($fechaCobro, (string) $reserva['fecha_ingreso']);
            if ($reserva['grupo_id'] !== null) {
                $grupo = (int) $reserva['grupo_id'];
                $limiteDelGrupo[$grupo] = min($limiteDelGrupo[$grupo] ?? $reserva['limite'], $reserva['limite']);
            }
            $deudoras[] = $reserva;
        }

        $pendientes = [];
        foreach ($deudoras as $reserva) {
            $limite = $reserva['grupo_id'] === null ? $reserva['limite'] : $limiteDelGrupo[(int) $reserva['grupo_id']];
            $numero = self::recordatorioQueToca($hoy, $limite);
            if ($numero === null) {
                continue;   //todavía no es momento, o ya se le pasó el plazo (lo que corresponde es cancelarla)
            }
            if ((int) $reserva['ultimo_numero'] >= $numero) {
                continue;   //ese recordatorio (o uno posterior) ya se le mandó
            }

            $pendientes[] = [
                'reserva_id'     => (int) $reserva['id'],
                'numero'         => $numero,
                'ultimo'         => $numero === count(self::AVISOS),
                'debe'           => $reserva['debe'],
                'ingreso'        => $reserva['fecha_ingreso'],
                'egreso'         => $reserva['fecha_egreso'],
                'fecha_cobro'    => $reserva['fecha_cobro'],
                'limite'         => $limite,
                'dias_restantes' => self::diasEntre($hoy, $limite),
                'vence_hoy'      => $limite === $hoy,
                'unidad'         => $reserva['unidad'],
                'huesped'        => ['id' => (int) $reserva['huesped_id'], 'nombre' => $reserva['huesped'], 'mail' => $reserva['mail']],
                'grupo_id'       => $reserva['grupo_id'] === null ? null : (int) $reserva['grupo_id'],
                'otras_reservas' => [],
            ];
        }
        return self::juntarGrupos($pendientes);
    }

    //Qué recordatorio le toca hoy a una reserva cuyo último día para pagar es $limite (AAAA-MM-DD), o null si ninguno.
    //Es el del aviso más cercano que ya llegó: a 3 o 2 días le toca el 1, a 1 día el 2 y el mismo día el 3. Si el cron
    //no corrió algún día, o reservó con 2 días de margen, se manda el que corresponde a hoy y no los atrasados.
    //Sin base ni reloj, para probarla sola.
    public static function recordatorioQueToca(string $hoy, string $limite): ?int
    {
        $dias = self::diasEntre($hoy, $limite);
        if ($dias < 0) {
            return null;    //se le pasó el plazo
        }
        $numero = null;
        foreach (self::AVISOS as $posicion => $antes) {
            if ($dias <= $antes) {
                $numero = $posicion + 1;
            }
        }
        return $numero;     //null si faltan más de 3 días
    }

    //Las unidades de una reserva grupal (US4.4) pagan el saldo juntas: en lugar de un mail por unidad, va UNO con el
    //total y los nombres de todas. Queda en la primera del grupo; 'otras_reservas' son las demás, para que el script
    //anote el recordatorio en todas y la cuenta de cada una siga igual. El plazo ya es el mismo para todas.
    private static function juntarGrupos(array $pendientes): array
    {
        $resultado = [];
        $primeraDelGrupo = [];//grupo_id → posición en $resultado
        foreach ($pendientes as $recordatorio) {
            $grupo = $recordatorio['grupo_id'];
            if ($grupo === null || !isset($primeraDelGrupo[$grupo])) {
                if ($grupo !== null) {
                    $primeraDelGrupo[$grupo] = count($resultado);
                }
                $resultado[] = $recordatorio;
                continue;
            }
            $primera = &$resultado[$primeraDelGrupo[$grupo]];
            $primera['debe'] = bcadd($primera['debe'], $recordatorio['debe'], 2);
            $primera['unidad'] .= ', ' . $recordatorio['unidad'];
            $primera['otras_reservas'][] = $recordatorio['reserva_id'];
            unset($primera);
        }
        return $resultado;
    }

    //El último día en que el huésped puede pagar el saldo (decisión #75 de docs/pagos-decisiones.md): la fecha de
    //cobro, hasta el final de ese día, sin días de gracia. Nunca después del ingreso: sin el saldo no se registra (#25).
    //Pasado ese día, la reserva se cancela sola con el tramo de menos de 15 días.
    //
    //Es público porque la cancelación automática (scripts/cancelar-saldos-vencidos.php), el pago del saldo y los
    //mails tienen que usar el MISMO plazo: si cada uno lo calculara por su cuenta, el mail diría una fecha y la
    //reserva se cancelaría en otra.
    public static function limiteDePago(string $fechaCobro, string $fechaIngreso): string
    {
        return min($fechaCobro, $fechaIngreso);
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

    //Días de calendario de $desde a $hasta (AAAA-MM-DD), negativo si $hasta ya pasó
    private static function diasEntre(string $desde, string $hasta): int
    {
        return (int) ValidadorFechas::fecha($desde)->diff(ValidadorFechas::fecha($hasta))->format('%r%a');
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
