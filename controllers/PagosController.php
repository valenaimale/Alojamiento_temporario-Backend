<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Mail\AvisoPagoMail;
use App\Request\Request;
use App\Validacion\ResultadoVerificacion;
use App\Validacion\TipoDeCambio;
use App\Validacion\VencimientoReserva;
use App\Validacion\Verificadores;

//El pago de la seña (L2-28) y el aviso si falla (L2-30). Plan de la entrega del 80 %, Rol 2.
//El huésped paga desde su billetera llamando a pagarSena(reservaId) del contrato (el ETH queda retenido ahí) y el front
//le pasa al back el hash de la transacción. El back la verifica (VerificadorPagos: hoy simulado, después Blockchain.php),
//la guarda en pagos (tabla de crear_tabla_pagos) y confirma la reserva.
//El monto se cobra en wei: los pesos de la seña pasados con el tipo de cambio fijo pesos_por_eth (TipoDeCambio).
//La reserva pendiente guarda sus noches solo hasta bloqueo_expira_en (VencimientoReserva): pasado ese plazo ya no se paga.
class PagosController
{
    private const TIPO_SENA = 'sena';

    //GET /pagos/sena?reserva_id=12: el monto de la seña (en pesos y en wei), hasta cuándo se puede pagar
    //y los datos que necesita el front para armar la transacción.
    public function sena()
    {
        Autorizacion::requiere('huesped');

        $reserva = $this->reservaDelHuesped(Request::parametroGet('reserva_id'));
        if ($reserva === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $problema = $this->problemaParaPagarSena($reserva);
        if ($problema !== null) {
            return $this->error(...$problema);
        }

        $pesosPorEth = TipoDeCambio::pesosPorEth();
        $montoWei = TipoDeCambio::aWei($reserva['importe_sena'], $pesosPorEth);
        $verificador = Verificadores::configurado();

        http_response_code(200);
        echo json_encode([
            'reserva_id'    => (int) $reserva['id'],
            'tipo'          => self::TIPO_SENA,
            'monto_pesos'   => $reserva['importe_sena'],
            'monto_wei'     => $montoWei,//texto: no entra en un número de JavaScript sin perder precisión
            'pesos_por_eth' => $pesosPorEth,
            'red'           => $verificador->red(),//chain id
            'vence_en'      => VencimientoReserva::venceEnIso($reserva['estado'], $reserva['bloqueo_expira_en']),
            'pago'          => $verificador->datosParaPagar((int) $reserva['id'], self::TIPO_SENA, $montoWei),
        ]);
    }

    //POST /pagos/confirmar: {"reserva_id", "tx_hash"}. Verifica la transacción del pago de la seña:
    //  - confirmada: guarda el pago y la reserva pasa a 'confirmada' (200)
    //  - todavía no está en un bloque: no guarda nada, el front vuelve a preguntar (202)
    //  - fallida: guarda el intento, le avisa al huésped por mail y responde por qué (402). Puede volver a pagar
    //    mientras no venza el plazo.
    public function confirmar()
    {
        Autorizacion::requiere('huesped');
        $datos = Request::datosJson();

        $txHash = $datos['tx_hash'] ?? null;
        if (!is_string($txHash) || preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash) !== 1) {
            return $this->error('El hash de la transacción no es válido');
        }
        $txHash = strtolower($txHash);//el mismo hash en mayúsculas es la misma transacción

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba la reserva hasta el commit: si llegan dos confirmaciones juntas, la segunda espera y ve la reserva
            //ya pagada (así nunca hay dos pagos de seña confirmados). Mientras tanto se consulta la red:
            //con el verificador real conviene que tarde poco, con un timeout.
            $reserva = $this->reservaDelHuesped($datos['reserva_id'] ?? null, true);
            if ($reserva === false) {
                $pdo->rollBack();
                return $this->error('No encontramos esa reserva', 404);
            }
            $problema = $this->problemaParaPagarSena($reserva);
            if ($problema !== null) {
                if (VencimientoReserva::vencida($reserva['estado'], $reserva['bloqueo_expira_en'])) {
                    $pdo->prepare("UPDATE reservas SET estado = 'expirada' WHERE id = ? AND estado = 'pendiente'")->execute([$reserva['id']]);
                    $pdo->commit();
                } else {
                    $pdo->rollBack();
                }
                return $this->error(...$problema);
            }

            //una transacción paga una sola cosa (la base solo lo asegura por billetera: acá, para cualquiera)
            $consulta = $pdo->prepare('SELECT reserva_id, estado FROM pagos WHERE tx_hash = ? LIMIT 1');
            $consulta->execute([$txHash]);
            $usado = $consulta->fetch();
            if ($usado !== false) {
                $pdo->rollBack();
                return $this->error($usado['estado'] === 'fallido' && (int) $usado['reserva_id'] === (int) $reserva['id']
                    ? 'Esa transacción ya falló: tenés que pagar de nuevo'
                    : 'Esa transacción ya se usó para otro pago', 409);
            }

            $pesosPorEth = TipoDeCambio::pesosPorEth();
            $montoWei = TipoDeCambio::aWei($reserva['importe_sena'], $pesosPorEth);
            $verificador = Verificadores::configurado();
            $resultado = $verificador->verificar($txHash, (int) $reserva['id'], self::TIPO_SENA, $montoWei);

            if ($resultado->estado === ResultadoVerificacion::PENDIENTE) {
                $pdo->rollBack();
                http_response_code(202);
                echo json_encode(['pendiente' => true, 'mensaje' => 'La transacción todavía no se confirmó en la red. Esperá unos segundos y volvé a intentar']);
                return;
            }

            $confirmado = $resultado->estado === ResultadoVerificacion::CONFIRMADO;
            //un intento fallido se guarda si se sabe quién lo pagó (pagos.billetera es obligatoria);
            //una transacción que ni existe no deja rastro, solo el aviso
            if ($resultado->billetera !== null) {
                $ahora = VencimientoReserva::ahora();
                $pdo->prepare(
                    'INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, creado_en, confirmado_en)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $reserva['id'], self::TIPO_SENA, $reserva['importe_sena'], $montoWei, $pesosPorEth,
                    strtolower($resultado->billetera), $verificador->red(), $txHash, $resultado->estado,
                    $ahora, $confirmado ? $ahora : null,
                ]);
            }
            if ($confirmado) {
                $pdo->prepare("UPDATE reservas SET estado = 'confirmada' WHERE id = ?")->execute([$reserva['id']]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            //23000: otra petición guardó la misma transacción al mismo tiempo (tx_hash y billetera son únicos)
            if ($e->getCode() === '23000') {
                return $this->error('Esa transacción ya se usó para otro pago', 409);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;//lo atrapa el router y responde 500
        }

        $huesped = $_SESSION['usuario'];
        if ($confirmado) {
            AvisoPagoMail::senaConfirmada($huesped, $reserva['unidad_nombre'], $reserva['fecha_ingreso'], $reserva['fecha_egreso'], $reserva['importe_sena']);
            http_response_code(200);
            echo json_encode([
                'ok'      => "Pagaste la seña: tu reserva en {$reserva['unidad_nombre']} está confirmada",
                'reserva' => ['id' => (int) $reserva['id'], 'estado' => 'confirmada'],
                'pago'    => ['monto_pesos' => $reserva['importe_sena'], 'monto_wei' => $montoWei, 'tx_hash' => $txHash, 'red' => $verificador->red()],
            ]);
            return;
        }

        //L2-30: el motivo no se guarda (pagos no tiene dónde), va en la respuesta y en el mail
        AvisoPagoMail::senaFallida($huesped, $reserva['unidad_nombre'], $resultado->motivo, $reserva['bloqueo_expira_en']);
        $plazo = $reserva['bloqueo_expira_en'] === null
            ? ''
            : ' Podés pagar de nuevo hasta las ' . VencimientoReserva::horaArgentina($reserva['bloqueo_expira_en']);
        http_response_code(402);
        echo json_encode([
            'error'    => "No pudimos confirmar el pago de la seña: {$resultado->motivo}.$plazo",
            'motivo'   => $resultado->motivo,
            'vence_en' => VencimientoReserva::venceEnIso($reserva['estado'], $reserva['bloqueo_expira_en']),
        ]);
    }

    //La reserva del huésped logueado, con el nombre de la unidad, o false si no existe o es de otro
    //(la de otro huésped "no existe": así nadie averigua reservas ajenas probando números).
    //$bloquear: FOR UPDATE, dentro de una transacción.
    private function reservaDelHuesped(mixed $id, bool $bloquear = false): array|false
    {
        $reservaId = is_bool($id) ? false : filter_var($id ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($reservaId === false) {
            return false;
        }
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.bloqueo_expira_en, r.importe_sena, r.fecha_ingreso, r.fecha_egreso, u.nombre AS unidad_nombre
             FROM reservas r JOIN unidades u ON u.id = r.unidad_id
             WHERE r.id = ? AND r.huesped_id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$reservaId, $_SESSION['usuario']['id']]);
        return $consulta->fetch();
    }

    //Por qué no se puede pagar la seña de esa reserva, como [mensaje, código HTTP], o null si se puede
    private function problemaParaPagarSena(array $reserva): ?array
    {
        $estado = VencimientoReserva::estadoVisible($reserva['estado'], $reserva['bloqueo_expira_en']);
        return match ($estado) {
            'pendiente'  => null,
            'expirada'   => ['Se venció el plazo para pagar la seña y las fechas se liberaron. Si siguen libres, podés volver a reservar', 409],
            'confirmada', 'finalizada' => ['La seña de esa reserva ya está pagada', 409],
            'cancelada'  => ['Esa reserva está cancelada', 409],
            default      => ['Esa reserva no se puede pagar', 409],
        };
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
