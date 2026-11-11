<?php

namespace App\Validacion;

use App\Database\Conexion;
use App\Mail\AvisoPagoMail;

//La excepción del propietario a la retención (L2-19, decisiones #64 a #66 de docs/pagos-decisiones.md).
//Cuando un huésped cancela (o la plataforma cancela por el saldo vencido) y la política retiene algo, el propietario
//tiene 48 hs para devolverle al huésped lo que quiera de lo retenido, pero solo de SU parte: el administrador y la
//plataforma cobran siempre su comisión (Liquidacion::retenidoYMaximo). Se da una sola vez por reserva.
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
                    r.huesped_id, u.nombre AS unidad_nombre, h.nombre AS huesped_nombre, h.mail AS huesped_mail,
                    EXISTS (SELECT 1 FROM liquidaciones q WHERE q.reserva_id = r.id) AS liquidada
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             WHERE r.id = ? AND u.propietario_id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$reservaId, $propietarioId]);
        return $consulta->fetch();
    }

    //Hasta cuándo puede dar la excepción ('AAAA-MM-DD HH:MM:SS', UTC), o null si no corresponde (no la canceló el
    //huésped ni la plataforma, o se canceló antes de que existiera la excepción)
    public static function hasta(array $reserva): ?string
    {
        if ($reserva['estado'] !== 'cancelada' || $reserva['cancelada_en'] === null
            || !in_array($reserva['cancelada_por'], ['huesped', 'plataforma'], true)) {
            return null;
        }
        return (new \DateTimeImmutable($reserva['cancelada_en'], new \DateTimeZone('UTC')))
            ->modify('+' . Liquidacion::HORAS_EXCEPCION . ' hours')->format('Y-m-d H:i:s');
    }

    //Todo lo que el propietario necesita ver para decidir: hasta cuándo puede, cuánto se retuvo, cuánto puede devolver
    //como máximo, si ya la dio, y por qué no puede (si no puede). Los montos van en pesos.
    public static function estado(array $reserva): array
    {
        $hasta = self::hasta($reserva);
        $montos = Liquidacion::retenidoYMaximo((int) $reserva['id']) ?? ['retenido' => '0.00', 'maximo' => '0.00'];
        $problema = match (true) {
            $reserva['estado'] !== 'cancelada'                  => 'La reserva no está cancelada',
            $hasta === null                                     => 'Solo se puede dar una excepción cuando cancela el huésped',
            $reserva['excepcion'] !== null                      => 'Ya le diste una excepción a esta reserva',
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
        AvisoPagoMail::excepcionAlHuesped(
            ['id' => $reserva['huesped_id'], 'nombre' => $reserva['huesped_nombre'], 'mail' => $reserva['huesped_mail']],
            $reserva['unidad_nombre'], $monto
        );
        return ['codigo' => 200, 'mensaje' => 'Listo: le devolvemos $' . number_format((float) $monto, 2, ',', '.')
            . ' al huésped. Le llega en los próximos minutos, junto con tu liquidación'];
    }
}
