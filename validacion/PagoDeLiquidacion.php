<?php

namespace App\Validacion;

use App\Database\Conexion;
use App\Log\Log;

//Paga una liquidación de verdad: llama a liquidar(reservaId, destinatarios, montos) del contrato PasarelaPago, que le
//manda a cada uno su parte de lo que la reserva tiene retenido (decisiones #58 a #61 de docs/pagos-decisiones.md).
//La usan dos caminos, que tienen que hacer exactamente lo mismo con la plata:
//  - el backoffice (POST /liquidaciones/pagar, LiquidacionesController);
//  - el cron de las tareas frecuentes (scripts/pagar-liquidaciones.php), que además reintenta y confirma.
//
//Orden, igual que las devoluciones (#35): en una transacción de la base se traba la liquidación y se anota un pago
//'liquidacion' pendiente por destinatario; después se manda UNA transacción con todos; se anota su tx_hash y, cuando
//se confirma en un bloque, la liquidación queda 'pagada'. Si la red falla, los pagos quedan pendientes sin tx_hash
//y los vuelve a mandar el script.
//
//Destinatarios: el propietario (su parte, con el IVA), el administrador (su comisión, si la unidad tiene) y la
//plataforma (su comisión, a la billetera dueña del contrato). Se reparte en wei lo que le queda a la reserva en el
//contrato, en la proporción de los pesos (Liquidacion::repartirWei): nunca se pide más de lo que hay.
class PagoDeLiquidacion
{
    //Los tipos de pago que salen con liquidar(): las partes de la liquidación y la excepción al huésped (#65)
    public const TIPOS = "'liquidacion', 'excepcion'";

    //Paga la liquidación $liquidacionId. Devuelve ['codigo' => HTTP, 'mensaje' => texto, 'tx_hash' => ?string]:
    //200 pagada, 202 salió y falta que se confirme, 404 no existe, 409 no se puede pagar (y por qué),
    //503 la red falló (queda pendiente y la reintenta el script).
    public static function pagar(int $liquidacionId, ?FirmadorPagos $firmador = null): array
    {
        $firmador ??= Firmadores::configurado();
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //Traba la liquidación: si el backoffice y el cron la pagan a la vez, el segundo espera y ve los pagos del primero
            $consulta = $pdo->prepare(
                "SELECT q.id, q.reserva_id, q.estado, q.administrador_id, q.importe_propietario, q.comision_administrador,
                        q.comision_plataforma, q.excepcion, p.billetera AS propietario_billetera, a.billetera AS administrador_billetera,
                        (SELECT s.billetera FROM pagos s WHERE s.reserva_id = q.reserva_id AND s.tipo = 'sena' AND s.estado = 'confirmado'
                          ORDER BY s.id LIMIT 1) AS huesped_billetera
                 FROM liquidaciones q
                 JOIN propietarios p ON p.usuario_id = q.propietario_id
                 LEFT JOIN administradores a ON a.usuario_id = q.administrador_id
                 WHERE q.id = ? FOR UPDATE"
            );
            $consulta->execute([$liquidacionId]);
            $liquidacion = $consulta->fetch();

            $problema = self::problema($liquidacion, (int) ($liquidacion['reserva_id'] ?? 0));
            if ($problema !== null) {
                $pdo->rollBack();
                return $problema;
            }

            $reservaId = (int) $liquidacion['reserva_id'];
            [$disponible, $pesosPorEth] = self::disponible($reservaId, $firmador->red());
            if (bccomp($disponible, '0', 0) <= 0) {
                $pdo->rollBack();
                return self::resultado(409, "A esta reserva no le queda nada en el contrato de la red {$firmador->red()}: no hay qué liquidar");
            }

            $partes = self::partes($liquidacion, $disponible, $firmador->direccion());
            $insercion = $pdo->prepare(
                "INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'pendiente', ?)"
            );
            foreach ($partes as $billetera => $parte) {
                $insercion->execute([$reservaId, $parte['tipo'], $parte['pesos'], $parte['wei'], $pesosPorEth, $billetera, $firmador->red(), VencimientoReserva::ahora()]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        try {
            $envio = self::mandar($reservaId, $firmador);
        } catch (\Throwable $e) {
            Log::error('No se pudo mandar la liquidación', $e, ['liquidacion_id' => $liquidacionId, 'reserva_id' => $reservaId]);
            return self::resultado(503, 'No se pudo mandar la liquidación a la red: queda pendiente y se reintenta sola en unos minutos');
        }
        return $envio->confirmada
            ? self::resultado(200, 'Liquidación pagada', $envio->txHash)
            : self::resultado(202, 'La liquidación salió: se confirma en unos minutos', $envio->txHash);
    }

    //Manda en una sola transacción los pagos 'liquidacion' pendientes y sin tx_hash de la reserva, y anota el hash.
    //Si ya se confirmó, deja la liquidación pagada. Devuelve el envío, o null si no había nada que mandar.
    //Si la red falla, lanza la excepción y los pagos quedan como estaban (sin tx_hash).
    public static function mandar(int $reservaId, FirmadorPagos $firmador): ?EnvioTransaccion
    {
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            "SELECT id, billetera, monto_wei FROM pagos
             WHERE reserva_id = ? AND tipo IN (" . self::TIPOS . ") AND estado = 'pendiente' AND tx_hash IS NULL AND red = ?
             ORDER BY id"
        );
        $consulta->execute([$reservaId, $firmador->red()]);
        $pagos = $consulta->fetchAll();
        if ($pagos === []) {
            return null;
        }

        $envio = $firmador->liquidar($reservaId, array_column($pagos, 'billetera'), array_column($pagos, 'monto_wei'));
        $actualizacion = $pdo->prepare('UPDATE pagos SET tx_hash = ?, estado = ?, confirmado_en = ? WHERE id = ?');
        foreach ($pagos as $pago) {
            $actualizacion->execute([
                strtolower($envio->txHash), $envio->confirmada ? 'confirmado' : 'pendiente',
                $envio->confirmada ? VencimientoReserva::ahora() : null, $pago['id'],
            ]);
        }
        if ($envio->confirmada) {
            self::marcarPagada($reservaId);
        }
        return $envio;
    }

    //La transacción $txHash de la reserva se confirmó en un bloque: sus pagos quedan confirmados y la liquidación, pagada
    public static function confirmar(int $reservaId, string $txHash): void
    {
        Conexion::obtener()->prepare(
            "UPDATE pagos SET estado = 'confirmado', confirmado_en = ? WHERE tipo IN (" . self::TIPOS . ") AND estado = 'pendiente' AND tx_hash = ?"
        )->execute([VencimientoReserva::ahora(), $txHash]);
        self::marcarPagada($reservaId);
    }

    //Quiénes de esta liquidación todavía no cargaron su billetera de cobro (#42 y #44): ['propietario'],
    //['administrador'], los dos, o [] si se puede liquidar. En una unidad de autogestión no hay administrador.
    public static function faltanBilleteras(array $fila): array
    {
        $faltan = [];
        if ($fila['propietario_billetera'] === null) {
            $faltan[] = 'propietario';
        }
        if ($fila['administrador_id'] !== null && $fila['administrador_billetera'] === null) {
            $faltan[] = 'administrador';
        }
        return $faltan;
    }

    //Por qué no se puede pagar la liquidación (como resultado de pagar()), o null si se puede
    private static function problema(array|false $liquidacion, int $reservaId): ?array
    {
        if ($liquidacion === false) {
            return self::resultado(404, 'No encontramos esa liquidación');
        }
        if ($liquidacion['estado'] === 'pagada') {
            return self::resultado(409, 'Esa liquidación ya estaba pagada');
        }
        if ($liquidacion['estado'] === 'retenida_por_deuda') {
            return self::resultado(409, 'Esa liquidación está retenida: todavía no se cobró toda la estadía');
        }
        //Sin billetera no se liquida: el ETH se queda en el contrato hasta que la carguen (#44).
        //Nunca se le manda a otra billetera, porque eso mezclaría su plata con la de la plataforma.
        $faltan = self::faltanBilleteras($liquidacion);
        if ($faltan !== []) {
            $quien = match (true) {
                $faltan === ['propietario']   => 'El propietario todavía no cargó su billetera de cobro',
                $faltan === ['administrador'] => 'El administrador todavía no cargó su billetera de cobro',
                default                       => 'El propietario y el administrador todavía no cargaron su billetera de cobro',
            };
            return self::resultado(409, "$quien: hasta que la carguen, lo que cobró esta estadía sigue retenido en el contrato");
        }
        //Si ya hay pagos de esta liquidación, ya salió (o la está mandando otro pedido): no se manda dos veces
        //Si el propietario le dio una excepción al huésped, hace falta saber a qué billetera mandársela
        if (bccomp((string) $liquidacion['excepcion'], '0', 2) > 0 && $liquidacion['huesped_billetera'] === null) {
            return self::resultado(409, 'No encontramos la billetera con la que pagó el huésped: no se le puede mandar la excepción');
        }
        $consulta = Conexion::obtener()->prepare('SELECT COUNT(*) FROM pagos WHERE reserva_id = ? AND tipo IN (' . self::TIPOS . ')');
        $consulta->execute([$reservaId]);
        if ((int) $consulta->fetchColumn() > 0) {
            return self::resultado(409, 'Esa liquidación ya se está pagando: se confirma en unos minutos');
        }
        return null;
    }

    //Lo que le queda a la reserva en el contrato de esa red, en wei: lo que entró (seña y saldo confirmados) menos lo
    //que salió o está saliendo (devoluciones y liquidaciones). Devuelve [wei, pesos_por_eth del primer pago].
    private static function disponible(int $reservaId, int $red): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN tipo IN ('sena', 'saldo') THEN monto_wei ELSE -monto_wei END), 0) AS disponible,
                    (SELECT pesos_por_eth FROM pagos e WHERE e.reserva_id = ? AND e.red = ? AND e.estado = 'confirmado'
                       AND e.tipo IN ('sena', 'saldo') ORDER BY e.id LIMIT 1) AS pesos_por_eth
             FROM pagos
             WHERE reserva_id = ? AND red = ?
               AND ((tipo IN ('sena', 'saldo') AND estado = 'confirmado') OR (tipo IN ('devolucion', " . self::TIPOS . ") AND estado <> 'fallido'))"
        );
        $consulta->execute([$reservaId, $red, $reservaId, $red]);
        $fila = $consulta->fetch();
        return [(string) $fila['disponible'], (string) $fila['pesos_por_eth']];
    }

    //Los pagos a anotar, por billetera: ['0x…' => ['tipo' => 'liquidacion', 'pesos' => '363533.05', 'wei' => '…']].
    //La excepción del propietario (#65) va al huésped, a la billetera que pagó la seña, como pago de tipo 'excepcion'.
    //Si dos partes van a la misma billetera (por ejemplo, el propietario es su propio administrador), se suman:
    //el índice único (tx_hash, billetera) no deja anotar dos pagos de la misma transacción a la misma billetera.
    //Las partes en 0 no se mandan: el contrato rechaza los montos en 0.
    private static function partes(array $liquidacion, string $disponibleWei, string $plataforma): array
    {
        $importes = [
            'propietario'   => (string) $liquidacion['importe_propietario'],
            'administrador' => (string) $liquidacion['comision_administrador'],
            'plataforma'    => (string) $liquidacion['comision_plataforma'],
            'huesped'       => (string) $liquidacion['excepcion'],
        ];
        $billeteras = [
            'propietario'   => $liquidacion['propietario_billetera'],
            'administrador' => $liquidacion['administrador_billetera'],
            'plataforma'    => $plataforma,
            'huesped'       => $liquidacion['huesped_billetera'],
        ];
        $partes = [];
        foreach (Liquidacion::repartirWei($disponibleWei, $importes) as $quien => $wei) {
            if (bccomp($wei, '0', 0) <= 0 || bccomp($importes[$quien], '0', 2) <= 0) {
                continue;
            }
            $billetera = strtolower((string) $billeteras[$quien]);
            $tipo = $quien === 'huesped' ? 'excepcion' : 'liquidacion';
            $partes[$billetera] ??= ['tipo' => $tipo, 'pesos' => '0', 'wei' => '0'];
            if ($partes[$billetera]['tipo'] !== $tipo) {
                $partes[$billetera]['tipo'] = 'liquidacion';//el huésped es también otra parte: va como liquidación
            }
            $partes[$billetera]['pesos'] = bcadd($partes[$billetera]['pesos'], $importes[$quien], 2);
            $partes[$billetera]['wei'] = bcadd($partes[$billetera]['wei'], $wei, 0);
        }
        return $partes;
    }

    //La liquidación de la reserva queda pagada, con la fecha (en UTC, como el resto del back)
    private static function marcarPagada(int $reservaId): void
    {
        Conexion::obtener()->prepare(
            "UPDATE liquidaciones SET estado = 'pagada', liquidada_en = ? WHERE reserva_id = ? AND estado = 'pendiente'"
        )->execute([VencimientoReserva::ahora(), $reservaId]);
    }

    //Un resultado de pagar()
    private static function resultado(int $codigo, string $mensaje, ?string $txHash = null): array
    {
        return ['codigo' => $codigo, 'mensaje' => $mensaje, 'tx_hash' => $txHash === null ? null : strtolower($txHash)];
    }
}
