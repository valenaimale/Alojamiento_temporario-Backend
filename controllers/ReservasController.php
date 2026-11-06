<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\Estadia;
use App\Validacion\ValidadorFechas;
use App\Validacion\VencimientoReserva;

//Las reservas de la plataforma (Épica 4). La reserva se crea 'pendiente' y guarda sus noches durante
//minutos_bloqueo_reserva, mientras el huésped paga la seña (PagosController). Pagada, pasa a 'confirmada';
//si no paga a tiempo, deja de ocupar y se ve 'expirada' (VencimientoReserva). Sin seña (0 %), se confirma al reservar.
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
            //sin seña no hay nada que pagar: se confirma ya y no vence
            $sinSena = (float) $sena === 0.0;
            $estado = $sinSena ? 'confirmada' : 'pendiente';
            $expiraEn = $sinSena ? null : VencimientoReserva::vencimientoDesdeAhora();
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
            'ok'      => $sinSena
                ? "Reservaste {$unidad['nombre']}: tu reserva está confirmada"
                : "Reservaste {$unidad['nombre']}: pagá la seña antes de las " . VencimientoReserva::horaArgentina($expiraEn) . ' para confirmarla',
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
                    u.id AS unidad_id, u.nombre AS unidad_nombre, l.nombre AS localidad, l.provincia, f.url AS foto_principal
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN localidades l ON l.id = u.localidad_id
             LEFT JOIN fotos_unidad f ON f.unidad_id = u.id AND f.orden = 0
             WHERE r.huesped_id = ?
             ORDER BY r.fecha_ingreso DESC, r.id DESC'
        );
        $consulta->execute([$_SESSION['usuario']['id']]);

        $reservas = [];
        foreach ($consulta->fetchAll() as $fila) {
            $estado = VencimientoReserva::estadoVisible($fila['estado'], $fila['bloqueo_expira_en']);
            $datos = $this->datosDe($fila);
            $datos['estado'] = $estado;//una pendiente vencida se ve expirada (la clave queda en su lugar)
            $reservas[] = $datos + [
                'vence_en'       => VencimientoReserva::venceEnIso($fila['estado'], $fila['bloqueo_expira_en']),
                'puede_pagar'    => $estado === 'pendiente',
                'puede_cancelar' => $estado === 'pendiente',
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

    //POST /reservas/cancelar: {"reserva_id"}. El huésped cancela su reserva pendiente y las noches quedan libres.
    //Sin seña pagada no hay nada que devolver. Cancelar una reserva confirmada (con su política) es la Épica L2-12.
    public function cancelar()
    {
        Autorizacion::requiere('huesped');
        $datos = Request::datosJson();

        $reservaId = is_bool($datos['reserva_id'] ?? null)
            ? false
            : filter_var($datos['reserva_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reserva = false;
        if ($reservaId !== false) {
            //solo las suyas: la de otro huésped "no existe" (404), así nadie averigua reservas ajenas probando números
            $consulta = Conexion::obtener()->prepare('SELECT estado, bloqueo_expira_en FROM reservas WHERE id = ? AND huesped_id = ?');
            $consulta->execute([$reservaId, $_SESSION['usuario']['id']]);
            $reserva = $consulta->fetch();
        }
        if ($reserva === false) {
            return $this->error('No encontramos esa reserva', 404);
        }
        $estado = VencimientoReserva::estadoVisible($reserva['estado'], $reserva['bloqueo_expira_en']);
        if ($estado === 'cancelada') {
            return $this->error('Esa reserva ya estaba cancelada', 409);
        }
        if ($estado === 'expirada') {
            return $this->error('Esa reserva ya venció sin pagar la seña: sus noches ya están libres', 409);
        }
        if ($estado !== 'pendiente') {
            return $this->error('Por ahora solo se puede cancelar una reserva pendiente de pago', 409);
        }

        //UPDATE condicional: si llegan dos pedidos juntos, o se paga la seña al mismo tiempo, solo cambia si sigue pendiente
        $consulta = Conexion::obtener()->prepare(
            "UPDATE reservas SET estado = 'cancelada' WHERE id = ? AND " . VencimientoReserva::pendienteVigente()
        );
        $consulta->execute([$reservaId]);
        if ($consulta->rowCount() === 0) {
            return $this->error('La reserva cambió mientras la cancelabas: actualizá la página', 409);
        }

        http_response_code(200);
        echo json_encode(['ok' => 'Cancelaste la reserva: esas noches quedaron libres']);
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
        $consulta->execute([$unidad['id'], ValidadorFechas::hoy()->format('Y-m-d')]);

        $reservas = [];
        foreach ($consulta->fetchAll() as $fila) {
            $reservas[] = $this->datosDe($fila) + ['huesped' => $fila['huesped']];
        }

        http_response_code(200);
        echo json_encode(['reservas' => $reservas]);
    }

    //Los datos de una reserva como los devuelve la API (los mismos en todas las listas)
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
