<?php

//Vuelve a mandar las devoluciones que no salieron al cancelar (L2-3): las de pagos con tipo 'devolucion',
//estado 'pendiente' y sin tx_hash, porque la red falló. Plan de la entrega del 80 %, Rol 2.
//Se corre por consola, desde la raíz del backend, a mano o con un cron de Railway:
//
//    php scripts/reintentar-devoluciones.php
//
//Usa el mismo firmador que la app (pagos.firmador) y solo toca las devoluciones de su red.
//Para no mandar dos veces la misma devolución:
//  - solo toma las que tienen más de 5 minutos (las recién creadas las está mandando la cancelación);
//  - un candado de MySQL (GET_LOCK) impide que corran dos copias del script al mismo tiempo;
//  - igual, el contrato no deja devolver más ETH del que entró para esa reserva.

use App\Database\Conexion;
use App\Log\Log;
use App\Validacion\Firmadores;
use App\Validacion\VencimientoReserva;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$pdo = Conexion::obtener();
if ((int) $pdo->query("SELECT GET_LOCK('reintentar_devoluciones', 0)")->fetchColumn() !== 1) {
    echo "Ya hay otra copia del script corriendo: no hago nada.\n";
    exit(0);
}

$firmador = Firmadores::configurado();
$consulta = $pdo->prepare(
    "SELECT id, reserva_id, billetera, monto_wei, monto_pesos FROM pagos
     WHERE tipo = 'devolucion' AND estado = 'pendiente' AND tx_hash IS NULL AND red = ?
       AND creado_en < UTC_TIMESTAMP() - INTERVAL 5 MINUTE
     ORDER BY id"
);
$consulta->execute([$firmador->red()]);
$pendientes = $consulta->fetchAll();

if ($pendientes === []) {
    echo "No hay devoluciones pendientes.\n";
    exit(0);
}

$fallidas = 0;
foreach ($pendientes as $pago) {
    try {
        $envio = $firmador->devolver((int) $pago['reserva_id'], $pago['billetera'], $pago['monto_wei']);
        $pdo->prepare('UPDATE pagos SET tx_hash = ?, estado = ?, confirmado_en = ? WHERE id = ?')->execute([
            strtolower($envio->txHash), $envio->confirmada ? 'confirmado' : 'pendiente',
            $envio->confirmada ? VencimientoReserva::ahora() : null, $pago['id'],
        ]);
        echo "Reserva {$pago['reserva_id']}: devolución de \${$pago['monto_pesos']} mandada ({$envio->txHash}).\n";
    } catch (\Throwable $e) {
        $fallidas++;
        Log::error('No se pudo reintentar la devolución', $e, ['reserva_id' => $pago['reserva_id'], 'pago_id' => $pago['id']]);
        echo "Reserva {$pago['reserva_id']}: no se pudo mandar la devolución ({$e->getMessage()}).\n";
    }
}

$pdo->query("SELECT RELEASE_LOCK('reintentar_devoluciones')");
exit($fallidas > 0 ? 1 : 0);
