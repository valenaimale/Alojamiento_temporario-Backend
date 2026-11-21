<?php

namespace App\Validacion;

use App\Database\Conexion;

//La reserva grupal (US4.4 y US8.5): las unidades de una grupal que un huésped reserva en una sola vez.
//Decisiones del 20/11 (docs/api/reserva-grupal.md):
//  - cada unidad es una reserva común (con su propietario, su seña, su política y su liquidación), unida a las demás
//    por reservas.grupo_id;
//  - un solo huésped paga todo: la seña de todas juntas (pagarSenaGrupal) y después el saldo de todas juntas
//    (pagarSaldoGrupal), en una transacción cada uno. Tiene los mismos 10 minutos que una reserva común;
//  - cada unidad se cancela por separado, con su política, y se le devuelve al mismo huésped;
//  - el saldo del grupo vence en la fecha de cobro MÁS TEMPRANA de sus unidades (por si alguna cambió de fechas), y
//    si no se paga se cancelan todas juntas.
class ReservaGrupal
{
    public const SENA = 'sena';
    public const SALDO = 'saldo';

    //Crea el grupo y una reserva pendiente por unidad elegida, en una transacción.
    //$unidadIds: las que eligió el huésped de las que ofrece la grupal para esas fechas (UnidadGrupal::armar).
    //Devuelve ['error' => [mensaje, código HTTP]] o ['grupo_id', 'expira_en', 'reservas' => [[id, unidad_id, nombre,
    //personas, precio_total, sena]]].
    public static function crear(int $huespedId, int $grupalId, array $fechas, int $personas, array $unidadIds): array
    {
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba las unidades elegidas, en orden de id (dos grupos que comparten unidades las traban en el mismo
            //orden y no se bloquean entre sí). Igual que en una reserva común, el segundo que llega espera y después
            //ve las noches que reservó el primero.
            $marcas = implode(', ', array_fill(0, count($unidadIds), '?'));
            $consulta = $pdo->prepare(
                "SELECT id, propietario_id, nombre, capacidad, porcentaje_sena,
                        retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15
                 FROM unidades WHERE id IN ($marcas) ORDER BY id FOR UPDATE"
            );
            $consulta->execute($unidadIds);
            $unidades = [];
            foreach ($consulta->fetchAll() as $unidad) {
                $unidades[(int) $unidad['id']] = $unidad;
            }

            $armada = UnidadGrupal::armar($grupalId, $fechas['ingreso'], $fechas['egreso'], $personas);
            $problema = self::problemaDeLaEleccion($armada, $unidadIds, $unidades, $personas, $huespedId);
            if ($problema !== null) {
                $pdo->rollBack();
                return ['error' => $problema];
            }

            $ahora = VencimientoReserva::ahora();
            $expiraEn = VencimientoReserva::vencimientoDesdeAhora();
            $pdo->prepare('INSERT INTO grupos_reserva (huesped_id, grupal_id, creado_en) VALUES (?, ?, ?)')
                ->execute([$huespedId, $grupalId, $ahora]);
            $grupoId = (int) $pdo->lastInsertId();

            $ofrecidas = [];
            foreach ($armada['unidades'] as $unidad) {
                $ofrecidas[(int) $unidad['id']] = $unidad['estadia'];
            }
            $reservas = [];
            foreach (self::repartirPersonas($unidadIds, $unidades, $personas) as $unidadId => $deEsta) {
                $estadia = $ofrecidas[$unidadId];
                //el total con 5 días o menos (#75): todas las unidades tienen el mismo ingreso, así que o todas o ninguna
                $sena = Estadia::senaAlReservar($estadia['precio_total'], $unidades[$unidadId]['porcentaje_sena'], $fechas['ingreso']);
                if (bccomp($sena, '0', 2) <= 0) {//decisión #52: el contrato no acepta pagos sin ETH
                    $pdo->rollBack();
                    return ['error' => ['El total de la estadía es demasiado bajo para cobrar la seña', 422]];
                }
                $reservaId = NuevaReserva::insertar($unidades[$unidadId], $huespedId, $fechas['ingreso'], $fechas['egreso'],
                    $deEsta, $estadia, $sena, $ahora, $expiraEn, $grupoId);
                $reservas[] = [
                    'id'           => $reservaId,
                    'unidad_id'    => $unidadId,
                    'nombre'       => $unidades[$unidadId]['nombre'],
                    'personas'     => $deEsta,
                    'precio_total' => $estadia['precio_total'],
                    'sena'         => $sena,
                ];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['grupo_id' => $grupoId, 'expira_en' => $expiraEn, 'reservas' => $reservas];
    }

    //Las reservas de un grupo del huésped, de la primera a la última, con lo que hace falta para pagarlas.
    //Lista vacía si el grupo no existe o es de otro. $bloquear: FOR UPDATE, dentro de una transacción.
    public static function reservas(int $grupoId, int $huespedId, bool $bloquear = false): array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT r.id, r.estado, r.bloqueo_expira_en, r.importe_sena, r.monto_faltante, r.fecha_cobro, r.fecha_ingreso,
                    r.fecha_egreso, r.retencion_menos_de_15, u.nombre AS unidad_nombre
             FROM reservas r JOIN unidades u ON u.id = r.unidad_id
             WHERE r.grupo_id = ? AND r.huesped_id = ?
             ORDER BY r.id' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$grupoId, $huespedId]);
        return $consulta->fetchAll();
    }

    //Las reservas del grupo que se pagan ahora: para la seña, las pendientes sin vencer; para el saldo, las confirmadas
    //que todavía deben (una unidad cancelada no se paga). Cada una con su 'monto' en pesos.
    public static function aPagar(string $tipo, array $reservas): array
    {
        $aPagar = [];
        foreach ($reservas as $reserva) {
            $estado = VencimientoReserva::estadoVisible($reserva['estado'], $reserva['bloqueo_expira_en']);
            if ($tipo === self::SENA && $estado === 'pendiente') {
                $aPagar[] = $reserva + ['monto' => $reserva['importe_sena']];
            }
            if ($tipo === self::SALDO && $estado === 'confirmada'
                && $reserva['monto_faltante'] !== null && bccomp($reserva['monto_faltante'], '0', 2) > 0) {
                $aPagar[] = $reserva + ['monto' => $reserva['monto_faltante']];
            }
        }
        return $aPagar;
    }

    //Por qué no se puede pagar la seña o el saldo del grupo, como [mensaje, código HTTP], o null si se puede
    public static function problemaParaPagar(string $tipo, array $reservas, array $aPagar): ?array
    {
        if ($reservas === []) {
            return ['No encontramos esa reserva grupal', 404];
        }
        if ($aPagar !== []) {
            return null;
        }
        $estados = array_map(fn ($r) => VencimientoReserva::estadoVisible($r['estado'], $r['bloqueo_expira_en']), $reservas);
        if ($tipo === self::SENA) {
            return in_array('expirada', $estados, true) && !in_array('confirmada', $estados, true)
                ? ['Se venció el plazo para pagar la seña y las fechas se liberaron. Si siguen libres, podés volver a reservar', 409]
                : ['La seña de esa reserva grupal ya está pagada', 409];
        }
        return in_array('pendiente', $estados, true)
            ? ['Primero tenés que pagar la seña', 409]
            : ['El saldo de esa reserva grupal ya está pagado', 409];
    }

    //La fecha de cobro del grupo: la más temprana de las reservas que se pagan juntas (decisión del 20/11)
    public static function fechaCobro(array $aPagar): ?string
    {
        $fechas = array_filter(array_column($aPagar, 'fecha_cobro'));
        return $fechas === [] ? null : min($fechas);
    }

    //El último día para pagar el saldo de una reserva, como lo usa la cancelación automática (CobroDelSaldo::limiteDePago).
    //Si es parte de un grupo, cuenta desde la fecha de cobro más temprana de las unidades del grupo que todavía deben
    //(y el ingreso más temprano): así, si no se paga, se cancelan todas juntas (decisión del 20/11).
    public static function limiteDelSaldo(int $reservaId, string $fechaCobro, string $fechaIngreso): string
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT MIN(o.fecha_cobro) AS fecha_cobro, MIN(o.fecha_ingreso) AS fecha_ingreso
             FROM reservas r JOIN reservas o ON o.grupo_id = r.grupo_id
             WHERE r.id = ? AND o.estado = 'confirmada' AND o.monto_faltante > 0 AND o.fecha_cobro IS NOT NULL"
        );
        $consulta->execute([$reservaId]);
        $grupo = $consulta->fetch();
        if ($grupo !== false && $grupo['fecha_cobro'] !== null) {
            $fechaCobro = min($fechaCobro, $grupo['fecha_cobro']);
            $fechaIngreso = min($fechaIngreso, $grupo['fecha_ingreso']);
        }
        return CobroDelSaldo::limiteDePago($fechaCobro, $fechaIngreso);
    }

    //"Cabaña 4, Cabaña 5 y Cabaña 6": cómo se nombra el grupo en los mensajes y en los mails
    public static function nombres(array $reservas): string
    {
        $nombres = array_column($reservas, 'unidad_nombre');
        $ultimo = array_pop($nombres);
        return $nombres === [] ? (string) $ultimo : implode(', ', $nombres) . " y $ultimo";
    }

    //Por qué no se puede reservar esa elección, como [mensaje, código HTTP], o null si se puede.
    //Lo elegido tiene que estar entre lo que ofrece la grupal para esas fechas, ser al menos 2 unidades y tener lugar
    //para todas las personas (y al menos una persona por unidad: cada reserva es de alguien).
    private static function problemaDeLaEleccion(?array $armada, array $unidadIds, array $unidades, int $personas, int $huespedId): ?array
    {
        if ($armada === null) {
            return ['Esta reserva grupal ya no está disponible para esas fechas: alguna unidad se reservó mientras elegías', 409];
        }
        $ofrecidas = array_map(fn ($u) => (int) $u['id'], $armada['unidades']);
        if (array_diff($unidadIds, $ofrecidas) !== []) {
            return ['Alguna de las unidades que elegiste ya no está disponible en esas fechas', 409];
        }
        if (count($unidadIds) < UnidadGrupal::MINIMO_UNIDADES) {
            return ['Elegí al menos ' . UnidadGrupal::MINIMO_UNIDADES . ' unidades: con una sola, reservala desde la búsqueda', 422];
        }
        $capacidad = array_sum(array_map(fn ($id) => (int) $unidades[$id]['capacidad'], $unidadIds));
        if ($capacidad < $personas) {
            return ["Las unidades que elegiste tienen lugar para $capacidad personas: sumá otra", 422];
        }
        if ($personas < count($unidadIds)) {
            return ['Elegiste más unidades que personas', 422];
        }
        foreach ($unidadIds as $id) {
            if ((int) $unidades[$id]['propietario_id'] === $huespedId) {
                return ['No podés reservar tu propia unidad', 422];
            }
        }
        return null;
    }

    //Cuántas personas van en cada unidad: una en cada una y el resto llenando de la primera a la última.
    //Devuelve [unidad_id => personas], en el orden en que se eligieron.
    private static function repartirPersonas(array $unidadIds, array $unidades, int $personas): array
    {
        $reparto = array_fill_keys($unidadIds, 1);
        $restantes = $personas - count($unidadIds);
        foreach ($unidadIds as $id) {
            $suman = min($restantes, (int) $unidades[$id]['capacidad'] - 1);
            $reparto[$id] += $suman;
            $restantes -= $suman;
        }
        return $reparto;
    }
}
