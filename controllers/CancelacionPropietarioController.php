<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Mail\AvisoPagoMail;
use App\Request\Request;
use App\Validacion\CancelacionConDevolucion;
use App\Validacion\ExcepcionDeCancelacion;
use App\Validacion\VencimientoReserva;
use App\Validacion\VerificadorBlockchain;

//Lo que el PROPIETARIO puede hacer con las reservas de sus unidades (L2-19, decisiones #63 a #66):
//  - cancelar una reserva, con un motivo: se le devuelve al huésped TODO lo que pagó, seña y saldo;
//  - darle una excepción al huésped que canceló: devolverle, en las 48 hs siguientes, lo que quiera de su parte de
//    lo retenido (ExcepcionDeCancelacion).
//Solo el propietario: el administrador de la unidad no cancela ni da excepciones (#66).
//La reserva de otro propietario "no existe" (404), así nadie averigua reservas ajenas probando números.
class CancelacionPropietarioController
{
    private const MOTIVO_MINIMO = 5;
    private const MOTIVO_MAXIMO = 500;

    //POST /unidades/reservas/cancelar: {"reserva_id", "motivo"}. El propietario cancela una reserva de su unidad.
    //  - pendiente: no se pagó nada, no hay devolución; se le avisa al huésped.
    //  - confirmada (antes del ingreso): se le devuelve al huésped todo lo que pagó (CancelacionConDevolucion).
    public function cancelar()
    {
        Autorizacion::requiere('propietario');
        $datos = Request::datosJson();
        $propietarioId = (int) $_SESSION['usuario']['id'];

        $motivo = is_string($datos['motivo'] ?? null) ? trim($datos['motivo']) : '';
        $largo = mb_strlen($motivo);
        if ($largo < self::MOTIVO_MINIMO || $largo > self::MOTIVO_MAXIMO) {
            return $this->error('Contale al huésped por qué cancelás (de ' . self::MOTIVO_MINIMO . ' a ' . self::MOTIVO_MAXIMO . ' caracteres)', 422);
        }
        $reserva = $this->reserva($datos['reserva_id'] ?? null, $propietarioId);
        if ($reserva === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $problema = CancelacionConDevolucion::problema($reserva);
        if ($problema !== null) {
            return $this->error(...$problema);
        }

        if (VencimientoReserva::estadoVisible($reserva['estado'], $reserva['bloqueo_expira_en']) === 'pendiente') {
            //UPDATE condicional: si el huésped paga la seña al mismo tiempo, solo cambia si sigue pendiente
            if (CancelacionConDevolucion::marcarCancelada((int) $reserva['id'], 'propietario', $motivo, VencimientoReserva::pendienteVigente()) === 0) {
                return $this->error('La reserva cambió mientras la cancelabas: actualizá la página', 409);
            }
            AvisoPagoMail::cancelacionDelPropietario(
                ['id' => $reserva['huesped_id'], 'nombre' => $reserva['huesped_nombre'], 'mail' => $reserva['huesped_mail']],
                $reserva['unidad_nombre'], $reserva['fecha_ingreso'], $reserva['fecha_egreso'], $motivo, '0.00', false
            );
            http_response_code(200);
            echo json_encode(['ok' => 'Cancelaste la reserva: esas noches quedaron libres y le avisamos al huésped']);
            return;
        }

        //Al trabar la reserva se vuelve a mirar que siga siendo de una unidad suya y cancelable
        $resultado = CancelacionConDevolucion::cancelar((int) $reserva['id'], fn (array $trabada) =>
            (int) $trabada['propietario_id'] === $propietarioId && CancelacionConDevolucion::problema($trabada) === null, null, $motivo);
        if ($resultado === null) {
            return $this->error('La reserva cambió mientras la cancelabas: actualizá la página', 409);
        }
        ['devolucion' => $devolucion, 'pago_id' => $pagoId, 'envio' => $envio, 'red' => $red] = $resultado;
        $monto = '$' . number_format((float) $devolucion['devolucion'], 2, ',', '.');
        http_response_code(200);
        echo json_encode([
            'ok'         => "Cancelaste la reserva: esas noches quedaron libres y le devolvemos $monto al huésped (todo lo que pagó)",
            'devolucion' => [
                'pagado'     => ['sena' => $devolucion['pagado_sena'], 'saldo' => $devolucion['pagado_saldo']],
                'devolucion' => $devolucion['devolucion'],
                'estado'     => $pagoId === null ? null : ($envio !== null && $envio->confirmada ? 'confirmado' : 'pendiente'),
                'tx_hash'    => $envio?->txHash,
                'red'        => $pagoId === null ? null : $red,
                'simulado'   => $pagoId === null ? null : $red !== VerificadorBlockchain::RED_BASE_SEPOLIA,
            ],
        ]);
    }

    //GET /unidades/reservas/excepcion?reserva_id=12: lo que el propietario necesita para decidir la excepción:
    //cuánto se retuvo, cuánto puede devolver como máximo (su parte), hasta cuándo, y si puede. No cambia nada.
    public function verExcepcion()
    {
        Autorizacion::requiere('propietario');
        $id = $this->id(Request::parametroGet('reserva_id'));
        $reserva = $id === false ? false : ExcepcionDeCancelacion::reserva($id, (int) $_SESSION['usuario']['id']);
        if ($reserva === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        http_response_code(200);
        echo json_encode(ExcepcionDeCancelacion::estado($reserva));
    }

    //POST /unidades/reservas/excepcion: {"reserva_id", "monto"}. El propietario le devuelve al huésped que canceló
    //$monto pesos de su parte de lo retenido (#65). Sale con la liquidación de la reserva, en los próximos minutos.
    public function darExcepcion()
    {
        Autorizacion::requiere('propietario');
        $datos = Request::datosJson();
        $id = $this->id($datos['reserva_id'] ?? null);
        if ($id === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $resultado = ExcepcionDeCancelacion::dar($id, $datos['monto'] ?? null, (int) $_SESSION['usuario']['id']);
        if ($resultado['codigo'] >= 400) {
            return $this->error($resultado['mensaje'], $resultado['codigo']);
        }
        http_response_code(200);
        echo json_encode(['ok' => $resultado['mensaje']]);
    }

    //POST /unidades/reservas/excepcion/renunciar: {"reserva_id"}. El propietario decide no devolver nada más: se
    //aplica la política tal cual y la cancelada se liquida enseguida, sin esperar las 48 hs.
    public function renunciarExcepcion()
    {
        Autorizacion::requiere('propietario');
        $datos = Request::datosJson();
        $id = $this->id($datos['reserva_id'] ?? null);
        if ($id === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $resultado = ExcepcionDeCancelacion::renunciar($id, (int) $_SESSION['usuario']['id']);
        if ($resultado['codigo'] >= 400) {
            return $this->error($resultado['mensaje'], $resultado['codigo']);
        }
        http_response_code(200);
        echo json_encode(['ok' => $resultado['mensaje']]);
    }

    //La reserva de una unidad del propietario, con lo que hace falta para cancelarla, o false si no existe o es de otro
    private function reserva(mixed $id, int $propietarioId): array|false
    {
        $reservaId = $this->id($id);
        if ($reservaId === false) {
            return false;
        }
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.bloqueo_expira_en, r.fecha_ingreso, r.fecha_egreso, r.huesped_id, r.extiende_reserva_id,
                    u.nombre AS unidad_nombre, h.nombre AS huesped_nombre, h.mail AS huesped_mail
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             WHERE r.id = ? AND u.propietario_id = ?'
        );
        $consulta->execute([$reservaId, $propietarioId]);
        return $consulta->fetch();
    }

    //El id de la reserva: un entero positivo (del query string o del JSON), o false
    private function id(mixed $valor): int|false
    {
        return is_bool($valor) ? false : filter_var($valor ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
