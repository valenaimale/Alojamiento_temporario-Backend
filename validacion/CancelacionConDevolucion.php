<?php

namespace App\Validacion;

use App\Database\Conexion;
use App\Log\Log;
use App\Mail\AvisoPagoMail;

//Cancela una reserva CONFIRMADA y le devuelve al huésped lo que corresponde según la política (L2-3, L2-18).
//La usan dos caminos, que tienen que hacer exactamente lo mismo con la plata:
//  - el huésped que cancela (POST /reservas/cancelar, ReservasController);
//  - la cancelación automática del saldo vencido (decisión #23, scripts/cancelar-saldos-vencidos.php).
//Orden (decisión #35): se cancela y se anota la devolución 'pendiente' en una transacción de la base; se le avisa al
//propietario (L2-18); se manda devolver(...) al contrato; se anota el tx_hash; se le avisa al huésped.
//Si la red falla, la reserva queda cancelada igual y la devolución pendiente, sin tx_hash, para reintentarla.
class CancelacionConDevolucion
{
    //La reserva con todo lo que hace falta para cancelarla: la política que aceptó, la unidad, el propietario y el huésped.
    //$bloquear: FOR UPDATE, dentro de una transacción.
    public static function reserva(int $reservaId, bool $bloquear = false): array|false
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.bloqueo_expira_en, r.fecha_ingreso, r.fecha_egreso, r.fecha_cobro, r.monto_faltante,
                    r.retencion_mas_de_30, r.retencion_de_15_a_30, r.retencion_menos_de_15, r.huesped_id,
                    u.nombre AS unidad_nombre, p.id AS propietario_id, p.nombre AS propietario_nombre, p.mail AS propietario_mail,
                    h.nombre AS huesped_nombre, h.mail AS huesped_mail
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios p ON p.id = u.propietario_id
             JOIN usuarios h ON h.id = r.huesped_id
             WHERE r.id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$reservaId]);
        return $consulta->fetch();
    }

    //Cuánto se retiene y cuánto se devuelve si se cancela hoy (Devolucion), con los pagos confirmados de la reserva.
    public static function devolucion(array $reserva): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT tipo, monto_pesos, monto_wei, pesos_por_eth, billetera FROM pagos
             WHERE reserva_id = ? AND estado = 'confirmado' AND tipo IN ('sena', 'saldo')
             ORDER BY id"
        );
        $consulta->execute([$reserva['id']]);
        //las reservas anteriores al cobro no tienen fecha_cobro: se calcula como al reservar
        $reserva['fecha_cobro'] ??= Estadia::fechaCobro(ValidadorFechas::fecha($reserva['fecha_ingreso']))->format('Y-m-d');
        return Devolucion::calcular($reserva, $consulta->fetchAll(), ValidadorFechas::hoy());
    }

    //Cancela la reserva confirmada $reservaId y manda la devolución.
    //$sigueCancelable: recibe la reserva ya trabada y dice si todavía se puede cancelar (si otro pedido la cambió
    //mientras tanto, devuelve false y no se toca nada).
    //$limiteSaldo: null si cancela el huésped; la fecha límite del saldo (AAAA-MM-DD) si es la cancelación automática.
    //Devuelve null si la reserva cambió, o ['reserva', 'devolucion', 'pago_id', 'envio'].
    public static function cancelar(int $reservaId, callable $sigueCancelable, ?string $limiteSaldo = null): ?array
    {
        $firmador = Firmadores::configurado();
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba la reserva: si llegan dos cancelaciones juntas (o un pago del saldo), la segunda espera y ya la ve cancelada
            $reserva = self::reserva($reservaId, true);
            if ($reserva === false || $reserva['estado'] !== 'confirmada' || !$sigueCancelable($reserva)) {
                $pdo->rollBack();
                return null;
            }
            $devolucion = self::devolucion($reserva);
            $pdo->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = ?")->execute([$reserva['id']]);

            $pagoId = null;
            if (bccomp($devolucion['devolucion_wei'], '0', 0) > 0 && bccomp($devolucion['devolucion'], '0', 2) > 0) {
                $pdo->prepare(
                    "INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, creado_en)
                     VALUES (?, 'devolucion', ?, ?, ?, ?, ?, NULL, 'pendiente', ?)"
                )->execute([
                    $reserva['id'], $devolucion['devolucion'], $devolucion['devolucion_wei'], $devolucion['pesos_por_eth'],
                    strtolower($devolucion['billetera']), $firmador->red(), VencimientoReserva::ahora(),
                ]);
                $pagoId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        //L2-18: el propietario se entera antes de que salga la devolución
        AvisoPagoMail::cancelacionAlPropietario(
            ['id' => $reserva['propietario_id'], 'nombre' => $reserva['propietario_nombre'], 'mail' => $reserva['propietario_mail']],
            $reserva['unidad_nombre'], $reserva['fecha_ingreso'], $reserva['fecha_egreso'], $devolucion['retencion'], $devolucion['devolucion'],
            $limiteSaldo !== null
        );

        $envio = null;
        if ($pagoId !== null) {
            try {
                $envio = $firmador->devolver((int) $reserva['id'], $devolucion['billetera'], $devolucion['devolucion_wei']);
                $pdo->prepare('UPDATE pagos SET tx_hash = ?, estado = ?, confirmado_en = ? WHERE id = ?')->execute([
                    strtolower($envio->txHash), $envio->confirmada ? 'confirmado' : 'pendiente',
                    $envio->confirmada ? VencimientoReserva::ahora() : null, $pagoId,
                ]);
            } catch (\Throwable $e) {
                //la reserva ya está cancelada: la devolución queda pendiente, sin tx_hash, para reintentarla
                Log::error('No se pudo mandar la devolución', $e, [
                    'reserva_id' => $reserva['id'], 'pago_id' => $pagoId, 'tx_hash' => $envio?->txHash,
                ]);
                $envio = null;
            }
        }
        $enCamino = $pagoId !== null && ($envio === null || !$envio->confirmada);
        AvisoPagoMail::cancelacionAlHuesped(
            ['id' => $reserva['huesped_id'], 'nombre' => $reserva['huesped_nombre'], 'mail' => $reserva['huesped_mail']],
            $reserva['unidad_nombre'], $devolucion['devolucion'], $devolucion['retencion'], $enCamino, $limiteSaldo
        );

        return ['reserva' => $reserva, 'devolucion' => $devolucion, 'pago_id' => $pagoId, 'envio' => $envio];
    }
}
