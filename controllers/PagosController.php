<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Mail\AvisoPagoMail;
use App\Request\Request;
use App\Validacion\CobroDelSaldo;
use App\Validacion\Extension;
use App\Validacion\ResultadoVerificacion;
use App\Validacion\TipoDeCambio;
use App\Validacion\ValidadorFechas;
use App\Validacion\VencimientoReserva;
use App\Validacion\Verificadores;

//Los pagos de una reserva: la seña (L2-28) y el saldo (L2-6), con el aviso si fallan (L2-30, RF-26).
//Plan de la entrega del 80 %, Rol 2.
//El huésped paga desde su billetera llamando a pagarSena(reservaId) o pagarSaldo(reservaId) del contrato (el ETH queda
//retenido ahí) y el front le pasa al back el hash de la transacción. El back la verifica (VerificadorPagos: hoy simulado,
//después Blockchain.php) y la guarda en pagos (tabla de crear_tabla_pagos):
//  - la seña confirma la reserva. Se paga solo mientras la reserva pendiente guarda sus noches (bloqueo_expira_en,
//    VencimientoReserva): pasado ese plazo ya no.
//  - el saldo (reservas.monto_faltante) se paga con la reserva confirmada, hasta su fecha_cobro. Al pagarlo,
//    monto_faltante queda en 0.
//Los montos se cobran en wei: los pesos pasados con el tipo de cambio fijo pesos_por_eth (TipoDeCambio).
//Una extensión de la estadía (decisión #70) se paga igual que una seña, entera (porcentaje_sena 100) y sin saldo, mientras
//la estadía sigue en curso (Extension::problemaParaPagar). Al confirmarse queda confirmada, como cualquier seña.
class PagosController
{
    private const SENA = 'sena';
    private const SALDO = 'saldo';
    private const NOMBRES = [self::SENA => 'de la seña', self::SALDO => 'del saldo'];//"el pago de la seña", "el pago del saldo"

    //GET /pagos/sena?reserva_id=12: el monto de la seña (en pesos y en wei), hasta cuándo se puede pagar
    //y los datos que necesita el front para armar la transacción.
    public function sena()
    {
        $this->datosParaPagar(self::SENA);
    }

    //GET /pagos/saldo?reserva_id=12: lo mismo para el saldo, con su fecha de cobro
    public function saldo()
    {
        $this->datosParaPagar(self::SALDO);
    }

    //POST /pagos/confirmar: {"reserva_id", "tx_hash", "tipo"?}. Verifica la transacción de un pago:
    //  - confirmada: guarda el pago; la seña confirma la reserva y el saldo la deja sin nada que pagar (200)
    //  - todavía no está en un bloque: no guarda nada, el front vuelve a preguntar (202)
    //  - fallida: guarda el intento, le avisa al huésped por mail y responde por qué (402). Puede volver a pagar.
    //"tipo" (sena o saldo) es opcional: si no viene, una reserva pendiente paga la seña y una confirmada el saldo.
    public function confirmar()
    {
        Autorizacion::requiere('huesped');
        $datos = Request::datosJson();

        $txHash = $datos['tx_hash'] ?? null;
        if (!is_string($txHash) || preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash) !== 1) {
            return $this->error('El hash de la transacción no es válido');
        }
        $txHash = strtolower($txHash);//el mismo hash en mayúsculas es la misma transacción
        $tipoPedido = $datos['tipo'] ?? null;
        if ($tipoPedido !== null && !isset(self::NOMBRES[$tipoPedido])) {
            return $this->error('El tipo de pago tiene que ser sena o saldo');
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba la reserva hasta el commit: si llegan dos confirmaciones juntas, la segunda espera y ve la reserva
            //ya pagada (así nunca hay dos pagos confirmados de lo mismo). Mientras tanto se consulta la red:
            //con el verificador real conviene que tarde poco, con un timeout.
            //Si es una extensión, primero se traba su reserva original, en el mismo orden que el egreso (Extension::trabarOriginal)
            $reserva = $this->reservaDelHuesped($datos['reserva_id'] ?? null, true);
            if ($reserva === false) {
                $pdo->rollBack();
                return $this->error('No encontramos esa reserva', 404);
            }
            $tipo = $tipoPedido ?? ($reserva['estado'] === 'pendiente' ? self::SENA : self::SALDO);
            $problema = $this->problemaParaPagar($tipo, $reserva);
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

            $montoPesos = $this->monto($tipo, $reserva);
            $pesosPorEth = TipoDeCambio::pesosPorEth();
            $montoWei = TipoDeCambio::aWei($montoPesos, $pesosPorEth);
            $verificador = Verificadores::configurado();
            $resultado = $verificador->verificar($txHash, (int) $reserva['id'], $tipo, $montoWei);

            if ($resultado->estado === ResultadoVerificacion::PENDIENTE) {
                $pdo->rollBack();
                http_response_code(202);
                echo json_encode(['pendiente' => true, 'mensaje' => 'La transacción todavía no se confirmó en la red. Esperá unos segundos y volvé a intentar']);
                return;
            }

            $confirmado = $resultado->estado === ResultadoVerificacion::CONFIRMADO;
            //se guarda lo que entró de verdad al contrato (puede ser un poco más que lo pedido): las devoluciones se
            //calculan sobre eso (decisión #32)
            if ($confirmado && $resultado->montoWei !== null) {
                $montoWei = $resultado->montoWei;
            }
            //un intento fallido se guarda si se sabe quién lo pagó (pagos.billetera es obligatoria);
            //una transacción que ni existe no deja rastro, solo el aviso
            if ($resultado->billetera !== null) {
                $ahora = VencimientoReserva::ahora();
                $pdo->prepare(
                    'INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, creado_en, confirmado_en)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $reserva['id'], $tipo, $montoPesos, $montoWei, $pesosPorEth,
                    strtolower($resultado->billetera), $verificador->red(), $txHash, $resultado->estado,
                    $ahora, $confirmado ? $ahora : null,
                ]);
            }
            if ($confirmado) {
                $pdo->prepare($tipo === self::SENA
                    ? "UPDATE reservas SET estado = 'confirmada' WHERE id = ?"
                    : 'UPDATE reservas SET monto_faltante = 0 WHERE id = ?'
                )->execute([$reserva['id']]);
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
        $unidad = $reserva['unidad_nombre'];
        $esExtension = $reserva['extiende_reserva_id'] !== null;
        if ($confirmado) {
            if ($esExtension) {
                Extension::avisarConfirmada((int) $reserva['id']);//al huésped y al propietario
                $ok = "Pagaste la extensión: tu estadía en $unidad sigue hasta el " . ValidadorFechas::fecha($reserva['fecha_egreso'])->format('d/m/Y');
            } elseif ($tipo === self::SENA) {
                //decisión #23: desde la seña se le avisa hasta cuándo puede pagar el saldo y qué pasa si no lo paga
                $limite = $this->limiteDelSaldo($reserva);
                $saldo = $limite !== null && bccomp($reserva['monto_faltante'], '0', 2) > 0
                    ? ['monto' => $reserva['monto_faltante'], 'fecha_cobro' => $reserva['fecha_cobro'], 'limite' => $limite,
                       'retencion' => $reserva['retencion_menos_de_15']]
                    : null;
                AvisoPagoMail::senaConfirmada($huesped, $unidad, $reserva['fecha_ingreso'], $reserva['fecha_egreso'], $montoPesos, $saldo);
                $ok = "Pagaste la seña: tu reserva en $unidad está confirmada";
            } else {
                AvisoPagoMail::saldoConfirmado($huesped, $unidad, $reserva['fecha_ingreso'], $montoPesos);
                $ok = "Pagaste el saldo: tu reserva en $unidad está paga";
            }
            http_response_code(200);
            echo json_encode([
                'ok'      => $ok,
                'tipo'    => $tipo,
                'reserva' => ['id' => (int) $reserva['id'], 'estado' => 'confirmada'],
                'pago'    => ['monto_pesos' => $montoPesos, 'monto_wei' => $montoWei, 'tx_hash' => $txHash, 'red' => $verificador->red()],
            ]);
            return;
        }

        //L2-30 / RF-26: el motivo no se guarda (pagos no tiene dónde), va en la respuesta y en el mail
        if ($tipo === self::SENA) {
            AvisoPagoMail::senaFallida($huesped, $unidad, $resultado->motivo, $reserva['bloqueo_expira_en']);
            $plazo = $reserva['bloqueo_expira_en'] === null
                ? ''
                : ' Podés pagar de nuevo hasta las ' . VencimientoReserva::horaArgentina($reserva['bloqueo_expira_en']);
            $extra = ['vence_en' => VencimientoReserva::venceEnIso($reserva['estado'], $reserva['bloqueo_expira_en'])];
        } else {
            AvisoPagoMail::saldoFallido($huesped, $unidad, $resultado->motivo, $this->limiteDelSaldo($reserva));
            $plazo = ' ' . $this->plazoDelSaldo($reserva);
            $extra = ['fecha_cobro' => $reserva['fecha_cobro'], 'limite_pago' => $this->limiteDelSaldo($reserva)];
        }
        http_response_code(402);
        echo json_encode([
            'error'  => 'No pudimos confirmar el pago ' . self::NOMBRES[$tipo] . ": {$resultado->motivo}.$plazo",
            'tipo'   => $tipo,
            'motivo' => $resultado->motivo,
        ] + $extra);
    }

    //Responde lo que necesita el front para pagar la seña o el saldo de una reserva del huésped logueado
    private function datosParaPagar(string $tipo)
    {
        Autorizacion::requiere('huesped');

        $reserva = $this->reservaDelHuesped(Request::parametroGet('reserva_id'));
        if ($reserva === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $problema = $this->problemaParaPagar($tipo, $reserva);
        if ($problema !== null) {
            return $this->error(...$problema);
        }

        $montoPesos = $this->monto($tipo, $reserva);
        $pesosPorEth = TipoDeCambio::pesosPorEth();
        $montoWei = TipoDeCambio::aWei($montoPesos, $pesosPorEth);
        $verificador = Verificadores::configurado();

        $plazo = $tipo === self::SENA
            ? ['vence_en' => VencimientoReserva::venceEnIso($reserva['estado'], $reserva['bloqueo_expira_en'])]
            : ['fecha_cobro' => $reserva['fecha_cobro'], 'vencido' => $this->saldoVencido($reserva['fecha_cobro']),
               'limite_pago' => $this->limiteDelSaldo($reserva)];//el último día para pagarlo (decisión #23)

        http_response_code(200);
        echo json_encode([
            'reserva_id'    => (int) $reserva['id'],
            'tipo'          => $tipo,
            'monto_pesos'   => $montoPesos,
            'monto_wei'     => $montoWei,//texto: no entra en un número de JavaScript sin perder precisión
            'pesos_por_eth' => $pesosPorEth,
            'red'           => $verificador->red(),//chain id
        ] + $plazo + [
            'pago'          => $verificador->datosParaPagar((int) $reserva['id'], $tipo, $montoWei),
        ]);
    }

    //La reserva del huésped logueado, con el nombre de la unidad, o false si no existe o es de otro
    //(la de otro huésped "no existe": así nadie averigua reservas ajenas probando números).
    //$bloquear: FOR UPDATE, dentro de una transacción (si es una extensión, traba antes su reserva original).
    private function reservaDelHuesped(mixed $id, bool $bloquear = false): array|false
    {
        $reservaId = is_bool($id) ? false : filter_var($id ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($reservaId === false) {
            return false;
        }
        if ($bloquear) {
            Extension::trabarOriginal((int) $_SESSION['usuario']['id'], $reservaId);
        }
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.bloqueo_expira_en, r.extiende_reserva_id, r.importe_sena, r.monto_faltante, r.fecha_cobro, r.retencion_menos_de_15,
                    r.fecha_ingreso, r.fecha_egreso, u.nombre AS unidad_nombre
             FROM reservas r JOIN unidades u ON u.id = r.unidad_id
             WHERE r.id = ? AND r.huesped_id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$reservaId, $_SESSION['usuario']['id']]);
        return $consulta->fetch();
    }

    //Cuánto se paga, en pesos con 2 decimales: la seña o lo que falta del total
    private function monto(string $tipo, array $reserva): string
    {
        return $tipo === self::SENA ? $reserva['importe_sena'] : $reserva['monto_faltante'];
    }

    //Por qué no se puede pagar la seña o el saldo de esa reserva, como [mensaje, código HTTP], o null si se puede
    private function problemaParaPagar(string $tipo, array $reserva): ?array
    {
        $problemaDeExtension = Extension::problemaParaPagar($reserva, $tipo);
        if ($problemaDeExtension !== null) {
            return $problemaDeExtension;
        }
        $estado = VencimientoReserva::estadoVisible($reserva['estado'], $reserva['bloqueo_expira_en']);
        if ($estado === 'expirada') {
            return ['Se venció el plazo para pagar la seña y las fechas se liberaron. Si siguen libres, podés volver a reservar', 409];
        }
        if ($estado === 'cancelada') {
            return ['Esa reserva está cancelada', 409];
        }
        if ($tipo === self::SENA) {
            return match ($estado) {
                'pendiente'                => null,
                'confirmada', 'finalizada' => ['La seña de esa reserva ya está pagada', 409],
                default                    => ['Esa reserva no se puede pagar', 409],
            };
        }
        return match (true) {
            $estado === 'pendiente'  => ['Primero tenés que pagar la seña', 409],
            $estado === 'finalizada' => ['Esa estadía ya terminó', 409],
            $estado !== 'confirmada' => ['Esa reserva no se puede pagar', 409],
            $reserva['monto_faltante'] === null || bccomp($reserva['monto_faltante'], '0', 2) <= 0
                                     => ['El saldo de esa reserva ya está pagado', 409],
            default                  => null,
        };
    }

    //Si ya pasó el día de cobro del saldo sin pagarlo (RF-26). Se cuenta en días de Argentina, como las fechas de la reserva.
    private function saldoVencido(?string $fechaCobro): bool
    {
        return $fechaCobro !== null && $fechaCobro < ValidadorFechas::hoy()->format('Y-m-d');
    }

    //El último día para pagar el saldo (decisión #23): el mismo que anuncian los recordatorios y que usa la cancelación
    //automática (CobroDelSaldo::limiteDePago). null en las reservas viejas sin fecha_cobro.
    private function limiteDelSaldo(array $reserva): ?string
    {
        return $reserva['fecha_cobro'] === null ? null : CobroDelSaldo::limiteDePago($reserva['fecha_cobro'], $reserva['fecha_ingreso']);
    }

    //"Tenés tiempo hasta el 08/12/2026 inclusive: si no, la reserva se cancela sola."
    private function plazoDelSaldo(array $reserva): string
    {
        $limite = $this->limiteDelSaldo($reserva);
        if ($limite === null) {
            return 'Podés volver a intentarlo desde tus reservas.';
        }
        $dia = ValidadorFechas::fecha($limite)->format('d/m/Y');
        return "Tenés tiempo hasta el $dia inclusive: si no, la reserva se cancela sola.";
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
