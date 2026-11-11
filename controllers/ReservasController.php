<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\CancelacionConDevolucion;
use App\Validacion\Estadia;
use App\Validacion\ExcepcionDeCancelacion;
use App\Validacion\Liquidacion;
use App\Validacion\ValidadorFechas;
use App\Validacion\VencimientoReserva;
use App\Validacion\VerificadorBlockchain;

//Las reservas de la plataforma (Épica 4). La reserva se crea 'pendiente' y guarda sus noches durante
//minutos_bloqueo_reserva, mientras el huésped paga la seña (PagosController). Pagada, pasa a 'confirmada';
//si no paga a tiempo, deja de ocupar y se ve 'expirada' (VencimientoReserva). La seña es de al menos el 1 % (decisión #52).
//Las reglas de las fechas, el precio y las noches ocupadas están en Estadia: las mismas que ve el huésped en el detalle.
class ReservasController
{
    //POST /reservas/crear: {"unidad_id", "ingreso", "egreso", "personas", "acepta_politica": true}
    //Reserva un huésped (o un propietario, que también es huésped) con el mail verificado.
    public function crear()
    {
        Autorizacion::requiere('huesped');
        Autorizacion::requiereMailVerificado();
        if (trim((string) ($_SESSION['usuario']['dni'] ?? '')) === '') {
            return $this->error('Completá tu DNI para reservar');
        }
        $datos = Request::datosJson();

        $unidadId = filter_var($datos['unidad_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($unidadId === false || is_bool($datos['unidad_id'] ?? null)) {
            return $this->error('No encontramos ese alojamiento', 404);
        }
        $fechas = Estadia::validarFechas((string) ($datos['ingreso'] ?? ''), (string) ($datos['egreso'] ?? ''));
        if (isset($fechas['error'])) {
            return $this->error($fechas['error']);
        }
        $personas = is_bool($datos['personas'] ?? null)//filter_var tomaría true como 1
            ? false
            : filter_var($datos['personas'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if ($personas === false) {
            return $this->error('Indicá cuántas personas van a ser (de 1 a 50)');
        }
        if (($datos['acepta_politica'] ?? null) !== true) {
            return $this->error('Para reservar tenés que aceptar la política de cancelación');
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba la fila de la unidad hasta el commit: si dos huéspedes reservan las mismas fechas a la vez,
            //el segundo espera al primero y recién ahí mira las noches ocupadas, así nunca hay dos reservas superpuestas.
            //Es la primera lectura de la transacción: las siguientes ya ven lo que guardó el primero.
            $consulta = $pdo->prepare(
                "SELECT id, propietario_id, nombre, capacidad, disponible, porcentaje_sena,
                        retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15
                 FROM unidades WHERE id = ? AND estado_publicacion = 'publicada'
                 FOR UPDATE"
            );
            $consulta->execute([$unidadId]);
            $unidad = $consulta->fetch();

            $problema = $this->problemaParaReservar($unidad, $personas);
            if ($problema === null) {
                $estadia = Estadia::calcular($unidadId, $fechas['ingreso'], $fechas['egreso']);
                if (!$estadia['disponible']) {
                    $problema = [$estadia['motivo'], 409];
                }
            }
            if ($problema !== null) {
                $pdo->rollBack();
                return $this->error(...$problema);
            }

            //importe, seña y política se copian de la unidad: son las condiciones que el huésped acepta ahora.
            //"Ahora" en UTC como el servidor, calculado en PHP (la base local puede tener otra zona horaria).
            $ahora = VencimientoReserva::ahora();
            $sena = Estadia::sena($estadia['precio_total'], $unidad['porcentaje_sena']);
            //Toda reserva nace pendiente y se confirma al pagar la seña (decisión #52: la seña es de al menos el 1 %).
            //Una seña de $0 no se podría pagar con el contrato, que rechaza los pagos sin ETH.
            if (bccomp($sena, '0', 2) <= 0) {
                $pdo->rollBack();
                return $this->error('El total de la estadía es demasiado bajo para cobrar la seña', 422);
            }
            $estado = 'pendiente';
            $expiraEn = VencimientoReserva::vencimientoDesdeAhora();
            $pdo->prepare(
                'INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado,
                                       importe_total, porcentaje_sena, importe_sena, monto_faltante, fecha_cobro,
                                       retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15,
                                       politica_aceptada_en, bloqueo_expira_en, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $unidadId,
                $_SESSION['usuario']['id'],//quién reserva sale siempre de la sesión
                $fechas['ingreso']->format('Y-m-d'),
                $fechas['egreso']->format('Y-m-d'),
                $personas,
                $estado,
                $estadia['precio_total'],
                $unidad['porcentaje_sena'],
                $sena,
                bcsub($estadia['precio_total'], $sena, 2),//el saldo
                Estadia::fechaCobro($fechas['ingreso'])->format('Y-m-d'),
                $unidad['retencion_mas_de_30'],
                $unidad['retencion_de_15_a_30'],
                $unidad['retencion_menos_de_15'],
                $ahora,
                $expiraEn,
                $ahora,
            ]);
            $reservaId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;//lo atrapa el router y responde 500
        }

        http_response_code(201);
        echo json_encode([
            'ok'      => "Reservaste {$unidad['nombre']}: pagá la seña antes de las " . VencimientoReserva::horaArgentina($expiraEn) . ' para confirmarla',
            'reserva' => [
                'id'            => $reservaId,
                'estado'        => $estado,
                'ingreso'       => $fechas['ingreso']->format('Y-m-d'),
                'egreso'        => $fechas['egreso']->format('Y-m-d'),
                'noches'        => $estadia['noches'],
                'personas'      => $personas,
                'importe_total' => $estadia['precio_total'],
                'importe_sena'  => $sena,
                'vence_en'      => VencimientoReserva::venceEnIso($estado, $expiraEn),
            ],
        ]);
    }

    //GET /reservas/mias: las reservas del huésped logueado (todas, también las canceladas y expiradas), de la más nueva
    //a la más vieja. Cada una con su alojamiento, si se puede pagar la seña o cancelar, y hasta cuándo se puede pagar.
    public function mias()
    {
        Autorizacion::requiere('huesped');

        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.bloqueo_expira_en, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas, r.importe_total, r.importe_sena,
                    r.monto_faltante, r.fecha_cobro, r.cancelada_por, r.motivo_cancelacion, r.excepcion,
                    u.id AS unidad_id, u.nombre AS unidad_nombre, l.nombre AS localidad, l.provincia, f.url AS foto_principal
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN localidades l ON l.id = u.localidad_id
             LEFT JOIN fotos_unidad f ON f.unidad_id = u.id AND f.orden = 0
             WHERE r.huesped_id = ?
             ORDER BY r.fecha_ingreso DESC, r.id DESC'
        );
        $consulta->execute([$_SESSION['usuario']['id']]);

        $hoy = ValidadorFechas::hoy()->format('Y-m-d');
        $reservas = [];
        foreach ($consulta->fetchAll() as $fila) {
            $estado = VencimientoReserva::estadoVisible($fila['estado'], $fila['bloqueo_expira_en']);
            $datos = $this->datosDe($fila);
            $datos['estado'] = $estado;//una pendiente vencida se ve expirada (la clave queda en su lugar)
            $reservas[] = $datos + [
                'vence_en'       => VencimientoReserva::venceEnIso($fila['estado'], $fila['bloqueo_expira_en']),
                'puede_pagar'    => $estado === 'pendiente',
                'puede_cancelar' => $estado === 'pendiente' || ($estado === 'confirmada' && $fila['fecha_ingreso'] > $hoy),
                //el saldo (L2-6): lo que falta pagar y hasta cuándo (null en las reservas anteriores al cobro)
                'saldo'             => $fila['monto_faltante'],
                'fecha_cobro'       => $fila['fecha_cobro'],
                'puede_pagar_saldo' => $estado === 'confirmada' && $fila['monto_faltante'] !== null && bccomp($fila['monto_faltante'], '0', 2) > 0,
                //quién la canceló y por qué (#63), y la excepción que le dio el propietario (#65); null si no está cancelada
                'cancelacion'       => $estado !== 'cancelada' ? null : [
                    'por'       => $fila['cancelada_por'],
                    'motivo'    => $fila['motivo_cancelacion'],
                    'excepcion' => $fila['excepcion'],
                ],
                'alojamiento'    => [
                    'id'             => (int) $fila['unidad_id'],
                    'nombre'         => $fila['unidad_nombre'],
                    'localidad'      => $fila['localidad'],
                    'provincia'      => $fila['provincia'],
                    'foto_principal' => $fila['foto_principal'],
                ],
            ];
        }

        http_response_code(200);
        echo json_encode(['reservas' => $reservas]);
    }

    //GET /reservas/cancelacion?reserva_id=12: cuánto se le devolvería al huésped si cancela ahora, según el tramo
    //de la política (L2-3: lo ve antes de confirmar). No cambia nada.
    public function cancelacion()
    {
        Autorizacion::requiere('huesped');

        $reserva = $this->reservaParaCancelar(Request::parametroGet('reserva_id'));
        if ($reserva === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $problema = $this->problemaParaCancelar($reserva);
        if ($problema !== null) {
            return $this->error(...$problema);
        }

        http_response_code(200);
        echo json_encode([
            'reserva_id' => (int) $reserva['id'],
            'estado'     => VencimientoReserva::estadoVisible($reserva['estado'], $reserva['bloqueo_expira_en']),
        ] + $this->datosDevolucion($this->devolucionDe($reserva)));
    }

    //POST /reservas/cancelar: {"reserva_id"}. El huésped cancela su reserva y las noches quedan libres.
    //  - pendiente: todavía no pagó nada, no hay devolución.
    //  - confirmada (antes del ingreso): se retiene el % de la seña del tramo y se le devuelve el resto, más el saldo
    //    si ya lo pagó (Devolucion). La devolución la manda la plataforma al contrato con devolver(...) (FirmadorPagos)
    //    y queda en pagos con tipo 'devolucion'. Antes se le avisa al propietario (L2-18).
    //La reserva se cancela primero y la devolución se manda después: si la red falla, la reserva queda cancelada igual
    //y la devolución queda 'pendiente', sin tx_hash, para reintentarla.
    public function cancelar()
    {
        Autorizacion::requiere('huesped');
        $datos = Request::datosJson();

        $reserva = $this->reservaParaCancelar($datos['reserva_id'] ?? null);
        if ($reserva === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $problema = $this->problemaParaCancelar($reserva);
        if ($problema !== null) {
            return $this->error(...$problema);
        }

        if (VencimientoReserva::estadoVisible($reserva['estado'], $reserva['bloqueo_expira_en']) === 'pendiente') {
            //UPDATE condicional: si llegan dos pedidos juntos, o se paga la seña al mismo tiempo, solo cambia si sigue pendiente
            if (CancelacionConDevolucion::marcarCancelada((int) $reserva['id'], 'huesped', null, VencimientoReserva::pendienteVigente()) === 0) {
                return $this->error('La reserva cambió mientras la cancelabas: actualizá la página', 409);
            }
            http_response_code(200);
            echo json_encode(['ok' => 'Cancelaste la reserva: esas noches quedaron libres']);
            return;
        }

        //La cancelación con devolución es la misma que la automática del saldo vencido (CancelacionConDevolucion).
        //Al trabar la reserva se vuelve a mirar que siga siendo del huésped y cancelable.
        $resultado = CancelacionConDevolucion::cancelar((int) $reserva['id'], fn (array $trabada) =>
            (int) $trabada['huesped_id'] === (int) $_SESSION['usuario']['id'] && $this->problemaParaCancelar($trabada) === null);
        if ($resultado === null) {
            return $this->error('La reserva cambió mientras la cancelabas: actualizá la página', 409);
        }
        ['devolucion' => $devolucion, 'pago_id' => $pagoId, 'envio' => $envio, 'red' => $red] = $resultado;
        $enCamino = $pagoId !== null && ($envio === null || !$envio->confirmada);

        $monto = '$' . number_format((float) $devolucion['devolucion'], 2, ',', '.');
        $ok = match (true) {
            $pagoId === null => 'Cancelaste la reserva: esas noches quedaron libres. Según la política de cancelación, no corresponde devolución',
            $enCamino        => "Cancelaste la reserva: esas noches quedaron libres. La devolución de $monto está en proceso: te avisamos por mail",
            default          => "Cancelaste la reserva: esas noches quedaron libres. Te devolvimos $monto a tu billetera",
        };
        http_response_code(200);
        echo json_encode([
            'ok'         => $ok,
            'devolucion' => $this->datosDevolucion($devolucion) + [
                'estado'  => $pagoId === null ? null : ($envio !== null && $envio->confirmada ? 'confirmado' : 'pendiente'),
                'tx_hash' => $envio?->txHash,
                //la red de la devolución: con 84532 (Base Sepolia) el front enlaza la transacción en BaseScan
                'red'      => $pagoId === null ? null : $red,
                'simulado' => $pagoId === null ? null : $red !== VerificadorBlockchain::RED_BASE_SEPOLIA,
            ],
        ]);
    }

    //GET /unidades/reservas?id=5: las reservas en curso y próximas de una unidad (pendientes y confirmadas),
    //de la más cercana a la más lejana. Las ven el propietario y el administrador asignado.
    //Del huésped va solo el nombre.
    public function deLaUnidad()
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador(Request::parametroGet('id'));//si no puede, responde y corta acá

        //"en curso y próximas": el egreso es de hoy en adelante (hoy en Argentina, calculado en PHP)
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.estado, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas, r.importe_total, r.importe_sena,
                    h.nombre AS huesped
             FROM reservas r
             JOIN usuarios h ON h.id = r.huesped_id
             WHERE r.unidad_id = ? AND " . VencimientoReserva::ocupaNoches('r') . " AND r.fecha_egreso >= ?
             ORDER BY r.fecha_ingreso, r.id"
        );
        $hoy = ValidadorFechas::hoy()->format('Y-m-d');
        $consulta->execute([$unidad['id'], $hoy]);

        $reservas = [];
        foreach ($consulta->fetchAll() as $fila) {
            $reservas[] = $this->datosDe($fila) + [
                'huesped'        => $fila['huesped'],
                //solo el propietario cancela (#66): una pendiente sin vencer o una confirmada antes del ingreso
                'puede_cancelar' => $unidad['es_propietario'] && ($fila['estado'] === 'pendiente' || $fila['fecha_ingreso'] > $hoy),
            ];
        }

        //Al propietario, además, las que canceló el huésped (o la plataforma) en las últimas 48 hs, para darle una
        //excepción (#64). El administrador no las ve: no puede darla.
        $canceladas = [];
        if ($unidad['es_propietario']) {
            $consulta = Conexion::obtener()->prepare(
                "SELECT r.id, r.estado, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas, r.importe_total, r.importe_sena,
                        r.cancelada_en, r.cancelada_por, r.excepcion, r.excepcion_en, h.nombre AS huesped,
                        EXISTS (SELECT 1 FROM liquidaciones q WHERE q.reserva_id = r.id) AS liquidada
                 FROM reservas r
                 JOIN usuarios h ON h.id = r.huesped_id
                 WHERE r.unidad_id = ? AND r.estado = 'cancelada' AND r.cancelada_por IN ('huesped', 'plataforma')
                   AND r.cancelada_en > UTC_TIMESTAMP() - INTERVAL " . Liquidacion::HORAS_EXCEPCION . " HOUR
                 ORDER BY r.cancelada_en DESC"
            );
            $consulta->execute([$unidad['id']]);
            foreach ($consulta->fetchAll() as $fila) {
                $canceladas[] = $this->datosDe($fila) + ['huesped' => $fila['huesped'], 'cancelada_por' => $fila['cancelada_por'],
                    'cancelada_en' => str_replace(' ', 'T', $fila['cancelada_en']) . 'Z', 'excepcion' => ExcepcionDeCancelacion::estado($fila)];
            }
        }

        http_response_code(200);
        echo json_encode(['reservas' => $reservas, 'canceladas' => $canceladas]);
    }

    //Los datos de una reserva como los devuelve la API (los mismos en todas las listas)
    //La reserva del huésped logueado con lo que hace falta para cancelarla (la política que aceptó, la unidad y su
    //propietario), o false si no existe o es de otro: la de otro huésped "no existe" (404), así nadie averigua
    //reservas ajenas probando números. $bloquear: FOR UPDATE, dentro de una transacción.
    private function reservaParaCancelar(mixed $id, bool $bloquear = false): array|false
    {
        $reservaId = is_bool($id) ? false : filter_var($id ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($reservaId === false) {
            return false;
        }
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.bloqueo_expira_en, r.fecha_ingreso, r.fecha_egreso, r.fecha_cobro,
                    r.retencion_mas_de_30, r.retencion_de_15_a_30, r.retencion_menos_de_15,
                    u.nombre AS unidad_nombre, p.id AS propietario_id, p.nombre AS propietario_nombre, p.mail AS propietario_mail
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios p ON p.id = u.propietario_id
             WHERE r.id = ? AND r.huesped_id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$reservaId, $_SESSION['usuario']['id']]);
        return $consulta->fetch();
    }

    //Por qué no se puede cancelar esa reserva, como [mensaje, código HTTP], o null si se puede.
    //Se cancela una pendiente sin vencer o una confirmada antes del día del ingreso.
    private function problemaParaCancelar(array $reserva): ?array
    {
        return CancelacionConDevolucion::problema($reserva);
    }

    //Cuánto se retiene y cuánto se devuelve si se cancela hoy (Devolucion), con los pagos confirmados de la reserva.
    //Una pendiente no tiene pagos: no se devuelve nada.
    private function devolucionDe(array $reserva): array
    {
        return CancelacionConDevolucion::devolucion($reserva);
    }

    //Lo que ve el huésped de una devolución (sin la billetera ni el tipo de cambio)
    private function datosDevolucion(array $devolucion): array
    {
        return [
            'tramo'                => $devolucion['tramo'],
            'dias_hasta_cobro'     => $devolucion['dias_hasta_cobro'],
            'porcentaje_retencion' => $devolucion['porcentaje_retencion'],
            'pagado'               => ['sena' => $devolucion['pagado_sena'], 'saldo' => $devolucion['pagado_saldo']],
            'retencion'            => $devolucion['retencion'],
            'devolucion'           => $devolucion['devolucion'],
            'devolucion_wei'       => $devolucion['devolucion_wei'],//texto, como monto_wei
        ];
    }

    private function datosDe(array $fila): array
    {
        return [
            'id'            => (int) $fila['id'],
            'estado'        => $fila['estado'],
            'ingreso'       => $fila['fecha_ingreso'],
            'egreso'        => $fila['fecha_egreso'],
            'noches'        => ValidadorFechas::noches(ValidadorFechas::fecha($fila['fecha_ingreso']), ValidadorFechas::fecha($fila['fecha_egreso'])),
            'personas'      => (int) $fila['cantidad_personas'],
            'importe_total' => $fila['importe_total'],
            'importe_sena'  => $fila['importe_sena'],
        ];
    }

    //Por qué no se puede reservar esa unidad, como [mensaje, código HTTP], o null si se puede.
    //Una unidad sin publicar "no existe" para el huésped (404), igual que en el detalle.
    private function problemaParaReservar(array|false $unidad, int $personas): ?array
    {
        if ($unidad === false) {
            return ['No encontramos ese alojamiento', 404];
        }
        if ((int) $unidad['propietario_id'] === (int) $_SESSION['usuario']['id']) {
            return ['No podés reservar tu propia unidad', 422];
        }
        if (!$unidad['disponible']) {
            return ['Este alojamiento no se puede reservar por ahora', 409];
        }
        if ($personas > (int) $unidad['capacidad']) {
            return ["Tiene lugar para {$unidad['capacidad']} personas", 422];
        }
        return null;
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
