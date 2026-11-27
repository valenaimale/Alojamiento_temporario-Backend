<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Mail\AvisoPagoMail;
use App\Repository\ReservaRepository;
use App\Request\Request;
use App\Request\Respuesta;
use App\Validacion\CobroDelSaldo;
use App\Validacion\Estadia;
use App\Validacion\ReservaGrupal;
use App\Validacion\ResultadoVerificacion;
use App\Validacion\TipoDeCambio;
use App\Validacion\ValidadorFechas;
use App\Validacion\VencimientoReserva;
use App\Validacion\Verificadores;

//La reserva grupal del huésped (US4.4): reservar varias unidades de una grupal y pagarlas juntas.
//Las reglas están en validacion/ReservaGrupal.php y en docs/api/reserva-grupal.md.
//Cada unidad es una reserva común: se cancela por separado con POST /reservas/cancelar, y se liquida sola.
//El pago es UNA transacción del contrato para todo el grupo (pagarSenaGrupal o pagarSaldoGrupal), que emite un
//PagoRealizado por reserva: el back la verifica reserva por reserva con el mismo verificador de un pago común.
class ReservasGrupalesController
{
    private const NOMBRES = [ReservaGrupal::SENA => 'de la seña', ReservaGrupal::SALDO => 'del saldo'];

    //POST /reservas/grupal/crear: {"grupal_id", "ingreso", "egreso", "personas", "unidad_ids": [..], "acepta_politica": true}
    //Reserva las unidades elegidas de la grupal: un grupo con una reserva pendiente por unidad, con los 10 minutos para
    //pagar la seña de todas juntas.
    public function crear()
    {
        Autorizacion::requiere('huesped');
        Autorizacion::requiereMailVerificado();
        if (trim((string) ($_SESSION['usuario']['dni'] ?? '')) === '') {
            return Respuesta::error('Completá tu DNI para reservar');
        }
        $datos = Request::datosJson(['unidad_ids']);

        $grupalId = Request::entero($datos['grupal_id'] ?? null);
        if ($grupalId === null) {
            return Respuesta::error('No encontramos esa reserva grupal', 404);
        }
        $fechas = Estadia::validarFechas((string) ($datos['ingreso'] ?? ''), (string) ($datos['egreso'] ?? ''));
        if (isset($fechas['error'])) {
            return Respuesta::error($fechas['error']);
        }
        $personas = Request::entero($datos['personas'] ?? null, 1, 50);
        if ($personas === null) {
            return Respuesta::error('Indicá cuántas personas van a ser (de 1 a 50)');
        }
        $unidadIds = $this->ids($datos['unidad_ids'] ?? null);
        if ($unidadIds === null) {
            return Respuesta::error('Elegí las unidades que querés reservar');
        }
        if (($datos['acepta_politica'] ?? null) !== true) {
            return Respuesta::error('Para reservar tenés que aceptar la política de cancelación de cada unidad');
        }

        $resultado = ReservaGrupal::crear((int) $_SESSION['usuario']['id'], $grupalId, $fechas, $personas, $unidadIds);
        if (isset($resultado['error'])) {
            return Respuesta::error(...$resultado['error']);
        }

        $sena = array_reduce($resultado['reservas'], fn ($suma, $r) => bcadd($suma, $r['sena'], 2), '0.00');
        $total = array_reduce($resultado['reservas'], fn ($suma, $r) => bcadd($suma, $r['precio_total'], 2), '0.00');
        Respuesta::json([
            'ok'    => 'Reservaste ' . count($resultado['reservas']) . ' unidades: pagá la seña antes de las '
                . VencimientoReserva::horaArgentina($resultado['expira_en']) . ' para confirmarlas',
            'grupo' => [
                'id'            => $resultado['grupo_id'],
                'ingreso'       => $fechas['ingreso']->format('Y-m-d'),
                'egreso'        => $fechas['egreso']->format('Y-m-d'),
                'personas'      => $personas,
                'importe_total' => $total,
                'importe_sena'  => $sena,
                'vence_en'      => VencimientoReserva::venceEnIso('pendiente', $resultado['expira_en']),
                'reservas'      => $resultado['reservas'],
            ],
        ], 201);
    }

    //GET /pagos/grupo/sena?grupo_id=3: cuánto es la seña del grupo (en pesos y en wei, en total y por reserva),
    //hasta cuándo se puede pagar y lo que necesita el front para llamar a pagarSenaGrupal(ids, montos).
    public function sena()
    {
        $this->datosParaPagar(ReservaGrupal::SENA);
    }

    //GET /pagos/grupo/saldo?grupo_id=3: lo mismo para el saldo de las unidades que siguen vigentes (pagarSaldoGrupal),
    //con la fecha de cobro del grupo (la más temprana) y el último día para pagarlo.
    public function saldo()
    {
        $this->datosParaPagar(ReservaGrupal::SALDO);
    }

    //POST /pagos/grupo/confirmar: {"grupo_id", "tx_hash", "tipo": "sena"|"saldo"}. Verifica la transacción del pago
    //grupal reserva por reserva. Como el contrato es todo o nada, o se confirman todas o ninguna:
    //  - confirmada: guarda un pago por reserva (todos con el mismo tx_hash); la seña confirma las reservas y el saldo
    //    las deja sin nada que pagar (200);
    //  - todavía no está en un bloque: no guarda nada, el front vuelve a preguntar (202);
    //  - fallida: guarda el intento de cada reserva, le avisa al huésped por mail y responde por qué (402).
    public function confirmar()
    {
        Autorizacion::requiere('huesped');
        $datos = Request::datosJson();
        $huespedId = (int) $_SESSION['usuario']['id'];

        $txHash = $datos['tx_hash'] ?? null;
        if (!is_string($txHash) || preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash) !== 1) {
            return Respuesta::error('El hash de la transacción no es válido');
        }
        $txHash = strtolower($txHash);
        $tipo = $datos['tipo'] ?? null;
        if (!isset(self::NOMBRES[$tipo])) {
            return Respuesta::error('El tipo de pago tiene que ser sena o saldo');
        }
        $grupoId = $this->grupoId($datos['grupo_id'] ?? null);

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba las reservas del grupo hasta el commit: si llegan dos confirmaciones juntas, la segunda espera y las
            //ve ya pagadas (nunca hay dos pagos confirmados de lo mismo)
            $reservas = $grupoId === null ? [] : ReservaGrupal::reservas($grupoId, $huespedId, true);
            $aPagar = ReservaGrupal::aPagar($tipo, $reservas);
            $problema = ReservaGrupal::problemaParaPagar($tipo, $reservas, $aPagar);
            if ($problema !== null) {
                //si la seña venció, la base queda en 'expirada' (como en un pago común)
                foreach ($reservas as $reserva) {
                    if (VencimientoReserva::vencida($reserva['estado'], $reserva['bloqueo_expira_en'])) {
                        ReservaRepository::marcarExpirada((int) $reserva['id']);
                    }
                }
                $pdo->commit();
                return Respuesta::error(...$problema);
            }

            //una transacción paga una sola cosa: no puede haberse usado para otro pago
            $consulta = $pdo->prepare('SELECT 1 FROM pagos WHERE tx_hash = ? LIMIT 1');
            $consulta->execute([$txHash]);
            if ($consulta->fetchColumn() !== false) {
                $pdo->rollBack();
                return Respuesta::error('Esa transacción ya se usó para otro pago', 409);
            }

            $pesosPorEth = TipoDeCambio::pesosPorEth();
            $verificador = Verificadores::configurado();
            $resultados = [];
            foreach ($aPagar as $reserva) {
                $montoWei = TipoDeCambio::aWei($reserva['monto'], $pesosPorEth);
                $resultado = $verificador->verificar($txHash, (int) $reserva['id'], $tipo, $montoWei);
                $resultados[] = [$reserva, $montoWei, $resultado];
            }
            $estados = array_map(fn ($r) => $r[2]->estado, $resultados);

            if (in_array(ResultadoVerificacion::PENDIENTE, $estados, true)) {
                $pdo->rollBack();
                Respuesta::json(['pendiente' => true, 'mensaje' => 'La transacción todavía no se confirmó en la red. Esperá unos segundos y volvé a intentar'], 202);
                return;
            }

            //todo o nada, como el contrato: si una reserva no se confirmó, el pago del grupo falló entero
            $confirmado = !in_array(ResultadoVerificacion::FALLIDO, $estados, true);
            $motivo = null;
            $ahora = VencimientoReserva::ahora();
            $insercion = $pdo->prepare(
                'INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, creado_en, confirmado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($resultados as [$reserva, $montoWei, $resultado]) {
                $motivo ??= $resultado->estado === ResultadoVerificacion::FALLIDO ? $resultado->motivo : null;
                if ($resultado->billetera === null) {
                    continue;//una transacción que ni existe no deja rastro, solo el aviso
                }
                //lo que entró de verdad al contrato (puede ser un poco más que lo pedido): de ahí salen las devoluciones (#32)
                $wei = $confirmado && $resultado->montoWei !== null ? $resultado->montoWei : $montoWei;
                $insercion->execute([
                    $reserva['id'], $tipo, $reserva['monto'], $wei, $pesosPorEth, strtolower($resultado->billetera),
                    $verificador->red(), $txHash, $confirmado ? ResultadoVerificacion::CONFIRMADO : ResultadoVerificacion::FALLIDO,
                    $ahora, $confirmado ? $ahora : null,
                ]);
                if ($confirmado) {
                    if ($tipo === ReservaGrupal::SENA) {
                        ReservaRepository::confirmar((int) $reserva['id']);
                    } else {
                        ReservaRepository::limpiarSaldo((int) $reserva['id']);
                    }
                }
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {//otra petición guardó la misma transacción al mismo tiempo
                return Respuesta::error('Esa transacción ya se usó para otro pago', 409);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $huesped = $_SESSION['usuario'];
        $nombres = ReservaGrupal::nombres($aPagar);
        $total = array_reduce($aPagar, fn ($suma, $r) => bcadd($suma, $r['monto'], 2), '0.00');
        $primera = $aPagar[0];
        if ($confirmado) {
            if ($tipo === ReservaGrupal::SENA) {
                $saldo = $this->saldoDelGrupo($grupoId, $huespedId);
                //sin saldo después de la seña: reservó con 5 días o menos y pagó el total de todas las unidades (#75)
                AvisoPagoMail::senaConfirmada($huesped, $nombres, $primera['fecha_ingreso'], $primera['fecha_egreso'], $total, $saldo, $saldo === null);
                $ok = $saldo === null
                    ? "Pagaste el total: tu reserva grupal ($nombres) está confirmada y paga"
                    : "Pagaste la seña: tu reserva grupal ($nombres) está confirmada";
            } else {
                AvisoPagoMail::saldoConfirmado($huesped, $nombres, $primera['fecha_ingreso'], $total);
                $ok = "Pagaste el saldo: tu reserva grupal ($nombres) está paga";
            }
            Respuesta::json([
                'ok'       => $ok,
                'tipo'     => $tipo,
                'grupo_id' => $grupoId,
                'pago'     => ['monto_pesos' => $total, 'tx_hash' => $txHash, 'red' => $verificador->red(),
                               'reservas' => array_map(fn ($r) => (int) $r['id'], $aPagar)],
            ]);
            return;
        }

        if ($tipo === ReservaGrupal::SENA) {
            AvisoPagoMail::senaFallida($huesped, $nombres, $motivo, $primera['bloqueo_expira_en']);
        } else {
            AvisoPagoMail::saldoFallido($huesped, $nombres, $motivo, $this->limite($aPagar));
        }
        Respuesta::json([
            'error'  => 'No pudimos confirmar el pago ' . self::NOMBRES[$tipo] . " de la reserva grupal: $motivo. Podés volver a intentarlo",
            'tipo'   => $tipo,
            'motivo' => $motivo,
        ], 402);
    }

    //Responde lo que necesita el front para pagar la seña o el saldo del grupo
    private function datosParaPagar(string $tipo)
    {
        Autorizacion::requiere('huesped');

        $grupoId = $this->grupoId(Request::parametroGet('grupo_id'));
        $reservas = $grupoId === null ? [] : ReservaGrupal::reservas($grupoId, (int) $_SESSION['usuario']['id']);
        $aPagar = ReservaGrupal::aPagar($tipo, $reservas);
        $problema = ReservaGrupal::problemaParaPagar($tipo, $reservas, $aPagar);
        if ($problema !== null) {
            return Respuesta::error(...$problema);
        }
        //Sin días de gracia (#75): pasado el último día no se arma un pago nuevo del saldo del grupo (se cancelan todas).
        $limite = $tipo === ReservaGrupal::SALDO ? $this->limite($aPagar) : null;
        if ($limite !== null && $limite < ValidadorFechas::hoy()->format('Y-m-d')) {
            return Respuesta::error('Se venció el plazo para pagar el saldo del grupo (era hasta el '
                . ValidadorFechas::fecha($limite)->format('d/m/Y') . '): las reservas se cancelan solas', 409);
        }

        $pesosPorEth = TipoDeCambio::pesosPorEth();
        $verificador = Verificadores::configurado();
        $detalle = [];
        $totalPesos = '0.00';
        $totalWei = '0';
        foreach ($aPagar as $reserva) {
            $montoWei = TipoDeCambio::aWei($reserva['monto'], $pesosPorEth);
            $detalle[] = ['reserva_id' => (int) $reserva['id'], 'unidad' => $reserva['unidad_nombre'],
                          'monto_pesos' => $reserva['monto'], 'monto_wei' => $montoWei];
            $totalPesos = bcadd($totalPesos, $reserva['monto'], 2);
            $totalWei = bcadd($totalWei, $montoWei, 0);//la suma de los montos de cada reserva: lo que exige el contrato
        }

        $plazo = $tipo === ReservaGrupal::SENA
            ? ['vence_en' => VencimientoReserva::venceEnIso('pendiente', $aPagar[0]['bloqueo_expira_en'])]
            : ['fecha_cobro' => ReservaGrupal::fechaCobro($aPagar), 'limite_pago' => $this->limite($aPagar)];
        $pago = $verificador->datosParaPagar((int) $aPagar[0]['id'], $tipo, $totalWei);
        $pago['funcion'] = $tipo === ReservaGrupal::SALDO ? 'pagarSaldoGrupal' : 'pagarSenaGrupal';

        Respuesta::json([
            'grupo_id'      => $grupoId,
            'tipo'          => $tipo,
            'monto_pesos'   => $totalPesos,
            'monto_wei'     => $totalWei,//texto: no entra en un número de JavaScript sin perder precisión
            'pesos_por_eth' => $pesosPorEth,
            'red'           => $verificador->red(),
        ] + $plazo + [
            'reservas'      => $detalle,//en este orden van los ids y los montos de la llamada al contrato
            'pago'          => $pago,
        ]);
    }

    //Lo que queda de saldo del grupo después de la seña, para el mail (#23), o null si no queda nada
    private function saldoDelGrupo(int $grupoId, int $huespedId): ?array
    {
        $saldos = ReservaGrupal::aPagar(ReservaGrupal::SALDO, ReservaGrupal::reservas($grupoId, $huespedId));
        if ($saldos === []) {
            return null;
        }
        return [
            'monto'       => array_reduce($saldos, fn ($suma, $r) => bcadd($suma, $r['monto'], 2), '0.00'),
            'fecha_cobro' => ReservaGrupal::fechaCobro($saldos),
            'limite'      => $this->limite($saldos),
            'retencion'   => $saldos[0]['retencion_menos_de_15'],
        ];
    }

    //El último día para pagar el saldo del grupo (#75): la fecha de cobro más temprana, sin días de gracia, y nunca
    //después del ingreso más temprano (CobroDelSaldo::limiteDePago)
    private function limite(array $aPagar): ?string
    {
        $fechaCobro = ReservaGrupal::fechaCobro($aPagar);
        return $fechaCobro === null ? null : CobroDelSaldo::limiteDePago($fechaCobro, min(array_column($aPagar, 'fecha_ingreso')));
    }

    //Los ids elegidos como lista de enteros sin repetir, o null si no es una lista de números
    private function ids(mixed $lista): ?array
    {
        if (!is_array($lista) || !array_is_list($lista) || $lista === []) {
            return null;
        }
        $ids = [];
        foreach ($lista as $id) {
            $entero = Request::entero($id);
            if ($entero === null) {
                return null;
            }
            $ids[$entero] = $entero;
        }
        return array_values($ids);
    }

    //El id del grupo como entero, o null si no es válido
    private function grupoId(mixed $id): ?int
    {
        return Request::entero($id);
    }
}
