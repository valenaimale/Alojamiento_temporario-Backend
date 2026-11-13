<?php

namespace App\Validacion;

use App\Database\Conexion;
use App\Mail\AvisoPagoMail;
use App\Mail\NoShowMail;

//La excepción del propietario a la retención (L2-19, decisiones #64 a #66 de docs/pagos-decisiones.md).
//Cuando un huésped cancela (o la plataforma cancela por el saldo vencido) y la política retiene algo, el propietario
//tiene 48 hs para devolverle al huésped lo que quiera de lo retenido, pero solo de SU parte: el administrador y la
//plataforma cobran siempre su comisión (Liquidacion::retenidoYMaximo). Se da una sola vez por reserva.
//
//El no-show también la habilita (#67): se trata igual que una cancelación del huésped con todo pagado, y las 48 hs
//corren desde no_show_en, el momento en que el sistema marcó la reserva (App\Validacion\NoShow).
//
//La excepción no se manda sola: queda anotada en la reserva y sale con la liquidación de la cancelada, en la misma
//transacción liquidar() (PagoDeLiquidacion), en los próximos minutos. Mientras corren las 48 hs, la cancelada no se
//liquida, así lo retenido sigue en el contrato.
class ExcepcionDeCancelacion
{
    //La reserva del propietario logueado, con lo que hace falta para la excepción, o false si no existe o es de otro.
    //$bloquear: FOR UPDATE, dentro de una transacción.
    public static function reserva(int $reservaId, int $propietarioId, bool $bloquear = false): array|false
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.fecha_ingreso, r.fecha_egreso, r.cancelada_en, r.cancelada_por, r.excepcion, r.excepcion_en,
                    r.no_show_en, r.huesped_id, u.nombre AS unidad_nombre, h.nombre AS huesped_nombre, h.mail AS huesped_mail,
                    EXISTS (SELECT 1 FROM liquidaciones q WHERE q.reserva_id = r.id) AS liquidada
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             WHERE r.id = ? AND u.propietario_id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$reservaId, $propietarioId]);
        return $consulta->fetch();
    }

    //Hasta cuándo puede dar la excepción ('AAAA-MM-DD HH:MM:SS', UTC), o null si no corresponde (la reserva no está
    //cancelada ni es un no-show, no la canceló el huésped ni la plataforma, o se canceló antes de que existiera la
    //excepción). En una cancelada, las 48 hs corren desde cancelada_en; en un no-show, desde no_show_en (#67).
    public static function hasta(array $reserva): ?string
    {
        $desde = match (true) {
            $reserva['estado'] === 'no_show'   => $reserva['no_show_en'] ?? null,
            $reserva['estado'] === 'cancelada' => in_array($reserva['cancelada_por'], ['huesped', 'plataforma'], true)
                ? $reserva['cancelada_en']
                : null,
            default                            => null,
        };
        if ($desde === null) {
            return null;
        }
        return (new \DateTimeImmutable($desde, new \DateTimeZone('UTC')))
            ->modify('+' . Liquidacion::HORAS_EXCEPCION . ' hours')->format('Y-m-d H:i:s');
    }

    //Todo lo que el propietario necesita ver para decidir: hasta cuándo puede, cuánto se retuvo, cuánto puede devolver
    //como máximo, si ya la dio, y por qué no puede (si no puede). Los montos van en pesos.
    public static function estado(array $reserva): array
    {
        $hasta = self::hasta($reserva);
        $montos = Liquidacion::retenidoYMaximo((int) $reserva['id']) ?? ['retenido' => '0.00', 'maximo' => '0.00'];
        $problema = match (true) {
            //la cancelada del huésped y el no-show son los dos casos con retención que habilitan la excepción (#64, #67)
            !in_array($reserva['estado'], ['cancelada', 'no_show'], true)
                                                                => 'La reserva no está cancelada ni quedó como no-show',
            $hasta === null                                     => 'Solo se puede dar una excepción cuando cancela el huésped',
            $reserva['excepcion'] !== null                      => 'Ya le diste una excepción a esta reserva',
            $reserva['excepcion_en'] !== null                   => 'Ya decidiste no devolverle nada al huésped',
            (bool) $reserva['liquidada']                        => 'Esta reserva ya se liquidó',
            $hasta <= VencimientoReserva::ahora()               => 'Pasaron las ' . Liquidacion::HORAS_EXCEPCION . ' hs para dar una excepción',
            bccomp($montos['maximo'], '0', 2) <= 0              => 'No se retuvo nada que puedas devolver',
            default                                             => null,
        };
        return [
            'reserva_id'   => (int) $reserva['id'],
            'retenido'     => $montos['retenido'],
            'maximo'       => $montos['maximo'],
            'hasta'        => $hasta === null ? null : str_replace(' ', 'T', $hasta) . 'Z',
            'excepcion'    => $reserva['excepcion'],
            //true si el propietario decidió no devolver nada (excepcion_en anotada, sin monto): ya no se espera
            'renuncio'     => $reserva['excepcion'] === null && $reserva['excepcion_en'] !== null,
            'puede'        => $problema === null,
            'motivo'       => $problema,
        ];
    }

    //Da la excepción de $monto pesos a la reserva $reservaId del propietario $propietarioId.
    //Devuelve ['codigo' => HTTP, 'mensaje' => texto]: 200 dada, 404 no existe o es de otro, 409 ya no se puede,
    //422 el monto no es válido. Si se dio, se genera enseguida la liquidación de la cancelada (el cron la paga).
    public static function dar(int $reservaId, mixed $monto, int $propietarioId): array
    {
        if (!is_string($monto) && !is_int($monto) && !is_float($monto) || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', (string) $monto)) {
            return ['codigo' => 422, 'mensaje' => 'El monto tiene que ser un número en pesos, con hasta 2 decimales'];
        }
        $monto = bcadd((string) $monto, '0', 2);
        if (bccomp($monto, '0', 2) <= 0) {
            return ['codigo' => 422, 'mensaje' => 'El monto tiene que ser mayor que cero'];
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //traba la reserva: si llegan dos pedidos juntos, el segundo ya la ve con la excepción
            $reserva = self::reserva($reservaId, $propietarioId, true);
            if ($reserva === false) {
                $pdo->rollBack();
                return ['codigo' => 404, 'mensaje' => 'No encontramos esa reserva'];
            }
            $estado = self::estado($reserva);
            if (!$estado['puede']) {
                $pdo->rollBack();
                return ['codigo' => 409, 'mensaje' => $estado['motivo']];
            }
            if (bccomp($monto, $estado['maximo'], 2) > 0) {
                $pdo->rollBack();
                return ['codigo' => 422, 'mensaje' => 'Podés devolver hasta $' . number_format((float) $estado['maximo'], 2, ',', '.')
                    . ': es lo que te toca a vos de lo retenido (el administrador y la plataforma cobran su comisión)'];
            }
            $pdo->prepare('UPDATE reservas SET excepcion = ?, excepcion_en = ? WHERE id = ?')
                ->execute([$monto, VencimientoReserva::ahora(), $reservaId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        //ya no hay que esperar las 48 hs: se liquida ahora (si la devolución ya se confirmó) y el cron la paga
        Liquidacion::generar($reservaId);
        //el aviso del no-show va en NoShowMail: lo que se retuvo no viene de una cancelación del huésped (#67)
        $huesped = ['id' => $reserva['huesped_id'], 'nombre' => $reserva['huesped_nombre'], 'mail' => $reserva['huesped_mail']];
        if ($reserva['estado'] === 'no_show') {
            NoShowMail::excepcionAlHuesped($huesped, $reserva['unidad_nombre'], $monto);
        } else {
            AvisoPagoMail::excepcionAlHuesped($huesped, $reserva['unidad_nombre'], $monto);
        }
        return ['codigo' => 200, 'mensaje' => 'Listo: le devolvemos $' . number_format((float) $monto, 2, ',', '.')
            . ' al huésped. Le llega en los próximos minutos, junto con tu liquidación'];
    }

    //El propietario decide no devolver nada más: se aplica la política de cancelación tal cual, sin esperar las 48 hs.
    //Queda anotado como excepcion_en con la fecha y excepcion en NULL (no hay monto). Desde ahí ya no puede dar una
    //excepción, y la cancelada se liquida enseguida. Devuelve ['codigo' => HTTP, 'mensaje' => texto], como dar().
    public static function renunciar(int $reservaId, int $propietarioId): array
    {
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //traba la reserva: si a la vez llega una excepción, la segunda en llegar ve la decisión ya tomada
            $reserva = self::reserva($reservaId, $propietarioId, true);
            if ($reserva === false) {
                $pdo->rollBack();
                return ['codigo' => 404, 'mensaje' => 'No encontramos esa reserva'];
            }
            $estado = self::estado($reserva);
            if (!$estado['puede']) {
                $pdo->rollBack();
                return ['codigo' => 409, 'mensaje' => $estado['motivo']];
            }
            $pdo->prepare('UPDATE reservas SET excepcion_en = ? WHERE id = ?')->execute([VencimientoReserva::ahora(), $reservaId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        //ya no hay que esperar las 48 hs: se liquida ahora (si la devolución ya se confirmó) y el cron la paga
        Liquidacion::generar($reservaId);
        return ['codigo' => 200, 'mensaje' => 'Listo: se aplica la política de cancelación. Tu parte de lo retenido te llega en los próximos minutos'];
    }
}
