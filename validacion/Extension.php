<?php

namespace App\Validacion;

use App\Database\Conexion;
use App\Log\Log;
use App\Mail\ExtensionMail;

//Extender una estadía en curso (US7.1, decisión #70 de docs/pagos-decisiones.md).
//Una extensión es una reserva nueva vinculada a la original (reservas.extiende_reserva_id), de las noches que siguen
//a la salida actual de la estadía (el egreso de la original o el de su última extensión paga). Cada noche lleva su
//precio y el descuento que alcanza la estadía completa (#69). No exige el mínimo de noches. Nace pendiente, guarda sus
//noches durante el mismo plazo que una seña y se paga entera en un solo pago: es la seña de esa reserva
//(porcentaje_sena 100, sin saldo). Pagada, queda confirmada y no se cancela. Se liquida apenas se confirma su pago (#71),
//porque el ingreso de la original ya está registrado.
//
//Bloqueos: crear una extensión traba primero la reserva original (y su unidad), igual que el egreso
//(EstadiasController). Así un egreso y una extensión de la misma estadía nunca se cruzan, y dos huéspedes que piden las
//mismas noches de la unidad a la vez (una reserva nueva y una extensión) tampoco: la reserva nueva traba la unidad.
class Extension
{
    public const MAXIMO_NOCHES = 30;

    //Si el huésped puede pedir una extensión de esta reserva. $reserva: una fila de reservas con, además,
    //ingreso_en y egreso_en de su estadía (null si no tiene). La usa GET /reservas/mias para mostrar el botón.
    //Una extensión no se extiende: se extiende la original, y la nueva extensión sigue a la última.
    public static function puedeExtender(array $reserva): bool
    {
        return $reserva['estado'] === 'confirmada'
            && ($reserva['extiende_reserva_id'] ?? null) === null
            && ($reserva['ingreso_en'] ?? null) !== null
            && ($reserva['egreso_en'] ?? null) === null;
    }

    //GET /reservas/extension: cuánto cuesta extender, sin guardar nada.
    //Devuelve ['cotizacion' => [...]] o ['error' => mensaje, 'codigo' => HTTP].
    public static function cotizar(int $huespedId, mixed $reservaId, mixed $noches): array
    {
        $pedido = self::validarPedido($reservaId, $noches);
        if (isset($pedido['error'])) {
            return $pedido;
        }
        $reserva = self::reservaDelHuesped($huespedId, $pedido['reserva_id'], false);
        if ($reserva === false) {
            return self::error('No encontramos esa reserva', 404);
        }
        return self::calcular($reserva, $pedido['noches']);
    }

    //POST /reservas/extender: crea la reserva de extensión pendiente de pago.
    //Devuelve ['reserva' => [...], 'vence_en_utc' => 'AAAA-MM-DD HH:MM:SS'] o ['error' => mensaje, 'codigo' => HTTP].
    public static function crear(int $huespedId, mixed $reservaId, mixed $noches): array
    {
        $pedido = self::validarPedido($reservaId, $noches);
        if (isset($pedido['error'])) {
            return $pedido;
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba la original y su unidad hasta el commit (ver el comentario de la clase)
            $reserva = self::reservaDelHuesped($huespedId, $pedido['reserva_id'], true);
            if ($reserva === false) {
                $pdo->rollBack();
                return self::error('No encontramos esa reserva', 404);
            }
            $resultado = self::calcular($reserva, $pedido['noches']);
            if (isset($resultado['error'])) {
                $pdo->rollBack();
                return $resultado;
            }
            $cotizacion = $resultado['cotizacion'];
            //Toda la extensión es la seña (porcentaje_sena 100). Una seña de $0 no se podría pagar con el contrato.
            if (bccomp($cotizacion['importe_total'], '0', 2) <= 0) {
                $pdo->rollBack();
                return self::error('El total de la extensión es demasiado bajo para cobrarlo', 422);
            }

            $ahora = VencimientoReserva::ahora();
            $expiraEn = VencimientoReserva::vencimientoDesdeAhora();
            //Las mismas personas y la misma política que la original. La política no se aplica (una extensión paga
            //no se cancela y una pendiente no pagó nada), pero las columnas son obligatorias. Sin saldo ni fecha de cobro.
            $pdo->prepare(
                "INSERT INTO reservas (unidad_id, huesped_id, extiende_reserva_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado,
                                       importe_sin_descuento, porcentaje_descuento,
                                       importe_total, porcentaje_sena, importe_sena, monto_faltante, fecha_cobro,
                                       retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15,
                                       politica_aceptada_en, bloqueo_expira_en, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, ?, 100, ?, 0, NULL, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $reserva['unidad_id'],
                $huespedId,
                $reserva['id'],
                $cotizacion['ingreso'],
                $cotizacion['egreso'],
                $reserva['cantidad_personas'],
                $cotizacion['importe_sin_descuento'],
                $cotizacion['porcentaje_descuento'],
                $cotizacion['importe_total'],
                $cotizacion['importe_total'],//la seña es el total
                $reserva['retencion_mas_de_30'],
                $reserva['retencion_de_15_a_30'],
                $reserva['retencion_menos_de_15'],
                $ahora,
                $expiraEn,
                $ahora,
            ]);
            $extensionId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;//lo atrapa el router y responde 500
        }

        return [
            'vence_en_utc' => $expiraEn,
            'reserva'      => [
                'id'                    => $extensionId,
                'estado'                => 'pendiente',
                'extiende_reserva_id'   => (int) $reserva['id'],
                'ingreso'               => $cotizacion['ingreso'],
                'egreso'                => $cotizacion['egreso'],
                'noches'                => $cotizacion['noches'],
                'importe_sin_descuento' => $cotizacion['importe_sin_descuento'],
                'porcentaje_descuento'  => $cotizacion['porcentaje_descuento'],
                'importe_total'         => $cotizacion['importe_total'],
                'importe_sena'          => $cotizacion['importe_total'],
                'vence_en'              => VencimientoReserva::venceEnIso('pendiente', $expiraEn),
            ],
        ];
    }

    //Por qué no se puede pagar una extensión pendiente, como [mensaje, código HTTP], o null si se puede.
    //La usa PagosController, con la original ya trabada (ver trabarOriginal). Una extensión se paga entera
    //como seña, y solo mientras la estadía sigue en curso: si ya se registró el egreso, el huésped ya se fue.
    //$extension: la fila de la reserva que se paga, con extiende_reserva_id; $tipo: 'sena' o 'saldo'.
    public static function problemaParaPagar(array $extension, string $tipo): ?array
    {
        if (($extension['extiende_reserva_id'] ?? null) === null) {
            return null;//no es una extensión
        }
        if ($tipo !== 'sena') {
            return ['Una extensión se paga entera, en un solo pago: no tiene saldo', 409];
        }
        if (VencimientoReserva::estadoVisible($extension['estado'], $extension['bloqueo_expira_en']) !== 'pendiente') {
            return null;//pagada, vencida o cancelada: lo responde la regla común de los pagos
        }
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.estado, e.ingreso_en, e.egreso_en FROM reservas r LEFT JOIN estadias e ON e.reserva_id = r.id WHERE r.id = ?'
        );
        $consulta->execute([$extension['extiende_reserva_id']]);
        $original = $consulta->fetch();
        if ($original === false || $original['estado'] !== 'confirmada' || $original['ingreso_en'] === null || $original['egreso_en'] !== null) {
            return ['Esa estadía ya terminó: la extensión no se puede pagar', 409];
        }
        return null;
    }

    //Traba la reserva original de una extensión antes de trabar la extensión, en el mismo orden que el egreso
    //(original → extensiones), así el pago de una extensión y el egreso de su estadía no se bloquean entre sí.
    //Si $reservaId no es una extensión del huésped, no hace nada. Va dentro de una transacción.
    public static function trabarOriginal(int $huespedId, int $reservaId): void
    {
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare('SELECT extiende_reserva_id FROM reservas WHERE id = ? AND huesped_id = ?');
        $consulta->execute([$reservaId, $huespedId]);
        $originalId = $consulta->fetchColumn();
        if ($originalId !== false && $originalId !== null) {
            $pdo->prepare('SELECT id FROM reservas WHERE id = ? FOR UPDATE')->execute([$originalId]);
        }
    }

    //Les avisa al huésped y al propietario que se confirmó (se pagó) una extensión. Nunca lanza excepciones:
    //el pago ya quedó registrado y la respuesta no tiene que fallar por un mail.
    public static function avisarConfirmada(int $extensionId): void
    {
        try {
            $consulta = Conexion::obtener()->prepare(
                'SELECT x.fecha_ingreso, x.fecha_egreso, x.importe_total, u.nombre AS unidad,
                        h.id AS huesped_id, h.nombre AS huesped_nombre, h.mail AS huesped_mail,
                        p.id AS propietario_id, p.nombre AS propietario_nombre, p.mail AS propietario_mail
                 FROM reservas x
                 JOIN unidades u ON u.id = x.unidad_id
                 JOIN usuarios h ON h.id = x.huesped_id
                 JOIN usuarios p ON p.id = u.propietario_id
                 WHERE x.id = ? AND x.extiende_reserva_id IS NOT NULL'
            );
            $consulta->execute([$extensionId]);
            $fila = $consulta->fetch();
            if ($fila === false) {
                return;
            }
            $huesped = ['id' => $fila['huesped_id'], 'nombre' => $fila['huesped_nombre'], 'mail' => $fila['huesped_mail']];
            $propietario = ['id' => $fila['propietario_id'], 'nombre' => $fila['propietario_nombre'], 'mail' => $fila['propietario_mail']];
            ExtensionMail::confirmadaAlHuesped($huesped, $fila['unidad'], $fila['fecha_ingreso'], $fila['fecha_egreso'], $fila['importe_total']);
            ExtensionMail::confirmadaAlPropietario($propietario, $fila['unidad'], $fila['huesped_nombre'], $fila['fecha_ingreso'], $fila['fecha_egreso']);
        } catch (\Throwable $e) {
            Log::error('No se pudo avisar la extensión confirmada', $e, ['reserva_id' => $extensionId]);
        }
    }

    //Valida el reserva_id y las noches del pedido. Devuelve ['reserva_id' => n, 'noches' => n] o un error.
    private static function validarPedido(mixed $reservaId, mixed $noches): array
    {
        $id = is_bool($reservaId) ? false : filter_var($reservaId ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return self::error('No encontramos esa reserva', 404);
        }
        $cantidad = is_bool($noches)//filter_var tomaría true como 1
            ? false
            : filter_var($noches ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAXIMO_NOCHES]]);
        if ($cantidad === false) {
            return self::error('Indicá cuántas noches más (de 1 a ' . self::MAXIMO_NOCHES . ')', 422);
        }
        return ['reserva_id' => $id, 'noches' => $cantidad];
    }

    //La reserva del huésped, o false si no existe o es de otro (la de otro "no existe": 404).
    //$bloquear: FOR UPDATE sobre la reserva y su unidad, dentro de una transacción.
    private static function reservaDelHuesped(int $huespedId, int $reservaId, bool $bloquear): array|false
    {
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT r.id, r.unidad_id, r.estado, r.extiende_reserva_id, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas,
                    r.retencion_mas_de_30, r.retencion_de_15_a_30, r.retencion_menos_de_15
             FROM reservas r JOIN unidades u ON u.id = r.unidad_id
             WHERE r.id = ? AND r.huesped_id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$reservaId, $huespedId]);
        $reserva = $consulta->fetch();
        if ($reserva === false) {
            return false;
        }
        //la estadía se lee con la original ya trabada: el egreso traba la original antes de tocarla
        $consulta = $pdo->prepare('SELECT ingreso_en, egreso_en FROM estadias WHERE reserva_id = ?');
        $consulta->execute([$reservaId]);
        $estadia = $consulta->fetch();
        return $reserva + ['ingreso_en' => $estadia['ingreso_en'] ?? null, 'egreso_en' => $estadia['egreso_en'] ?? null];
    }

    //Las condiciones del pedido y la cotización de las noches extra.
    //Devuelve ['cotizacion' => [...]] o ['error' => mensaje, 'codigo' => HTTP].
    private static function calcular(array $reserva, int $noches): array
    {
        if (!self::puedeExtender($reserva)) {
            return self::error('Solo se puede extender una estadía en curso', 409);
        }
        $pdo = Conexion::obtener();

        //La salida actual: el egreso de la original o el de su última extensión paga
        $consulta = $pdo->prepare(
            "SELECT fecha_ingreso, fecha_egreso FROM reservas WHERE extiende_reserva_id = ? AND estado IN ('confirmada', 'finalizada')"
        );
        $consulta->execute([$reserva['id']]);
        $salida = $reserva['fecha_egreso'];
        $nochesActuales = ValidadorFechas::noches(ValidadorFechas::fecha($reserva['fecha_ingreso']), ValidadorFechas::fecha($reserva['fecha_egreso']));
        foreach ($consulta->fetchAll() as $extension) {
            $salida = max($salida, $extension['fecha_egreso']);//AAAA-MM-DD se compara como texto
            $nochesActuales += ValidadorFechas::noches(ValidadorFechas::fecha($extension['fecha_ingreso']), ValidadorFechas::fecha($extension['fecha_egreso']));
        }
        //Si la salida ya pasó y nadie registró el egreso, no se extienden noches que ya pasaron
        if ($salida < ValidadorFechas::hoy()->format('Y-m-d')) {
            return self::error('Solo se puede extender una estadía en curso', 409);
        }

        //Una sola extensión esperando el pago a la vez
        $consulta = $pdo->prepare(
            'SELECT 1 FROM reservas WHERE extiende_reserva_id = ? AND ' . VencimientoReserva::pendienteVigente() . ' LIMIT 1'
        );
        $consulta->execute([$reserva['id']]);
        if ($consulta->fetchColumn() !== false) {
            return self::error('Ya tenés una extensión esperando el pago', 409);
        }

        $ingreso = ValidadorFechas::fecha($salida);
        $egreso = $ingreso->modify("+$noches days");
        //sin el mínimo de noches, y con el descuento de la estadía completa (#69, #70)
        $estadia = Estadia::calcular((int) $reserva['unidad_id'], $ingreso, $egreso, false, $nochesActuales + $noches);
        if (!$estadia['disponible']) {
            return self::error($estadia['motivo'], 409);
        }

        return ['cotizacion' => [
            'reserva_id'            => (int) $reserva['id'],
            'noches'                => $noches,
            'ingreso'               => $ingreso->format('Y-m-d'),
            'egreso'                => $egreso->format('Y-m-d'),
            'importe_sin_descuento' => $estadia['importe_sin_descuento'],
            'porcentaje_descuento'  => $estadia['porcentaje_descuento'],
            'importe_total'         => $estadia['precio_total'],
        ]];
    }

    private static function error(string $mensaje, int $codigo): array
    {
        return ['error' => $mensaje, 'codigo' => $codigo];
    }
}
