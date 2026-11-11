<?php

//Paga las liquidaciones con el contrato: llama a liquidar() para que cada uno reciba su parte en su billetera
//(PagoDeLiquidacion, decisiones #58 a #61 de docs/pagos-decisiones.md). Lo corre el cron de las tareas frecuentes,
//después de generar-liquidaciones.php. Se corre por consola, desde la raíz del backend:
//
//    php scripts/pagar-liquidaciones.php
//
//Hace tres cosas, en este orden:
//  1. confirma las que salieron y no llegaron a confirmarse en el momento: si la red las confirmó, la liquidación
//     queda pagada; si revirtieron o la red ya no las tiene, se les borra el tx_hash para mandarlas de nuevo;
//  2. vuelve a mandar las que no salieron porque la red falló (pagos 'liquidacion' pendientes sin tx_hash);
//  3. paga las liquidaciones pendientes que todavía no se mandaron. Las que no tienen billetera (#44) se saltean:
//     quedan para cuando la carguen.
//Para no pagar dos veces: los pasos 1 y 2 solo toman lo que tiene más de 5 minutos (lo más nuevo lo está mandando
//el backoffice), un candado de MySQL impide que corran dos copias, y el contrato nunca deja sacar de una reserva más
//ETH del que entró. Sale con 1 si alguna transacción no se pudo mandar.
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

use App\Database\Conexion;
use App\Log\Log;
use App\Validacion\Firmadores;
use App\Validacion\PagoDeLiquidacion;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$pdo = Conexion::obtener();
if ((int) $pdo->query("SELECT GET_LOCK('pagar_liquidaciones', 0)")->fetchColumn() !== 1) {
    echo "Ya hay otra copia del script corriendo: no hago nada.\n";
    exit(0);
}

$firmador = Firmadores::configurado();
$fallidas = 0;

//1. Las que ya salieron: ¿se confirmaron?
$enCamino = $pdo->prepare(
    "SELECT DISTINCT reserva_id, tx_hash FROM pagos
     WHERE tipo IN (" . PagoDeLiquidacion::TIPOS . ") AND estado = 'pendiente' AND tx_hash IS NOT NULL AND red = ?
       AND creado_en < UTC_TIMESTAMP() - INTERVAL 5 MINUTE
     ORDER BY reserva_id"
);
$enCamino->execute([$firmador->red()]);
foreach ($enCamino->fetchAll() as $envio) {
    try {
        $estado = $firmador->estado($envio['tx_hash']);
    } catch (\Throwable $e) {
        Log::error('No se pudo consultar una liquidación en camino', $e, ['reserva_id' => $envio['reserva_id']]);
        continue;
    }
    if ($estado === true) {
        PagoDeLiquidacion::confirmar((int) $envio['reserva_id'], $envio['tx_hash']);
        echo "Reserva {$envio['reserva_id']}: la liquidación {$envio['tx_hash']} se confirmó.\n";
    } elseif ($estado === false) {
        $pdo->prepare("UPDATE pagos SET tx_hash = NULL WHERE tipo IN (" . PagoDeLiquidacion::TIPOS . ") AND tx_hash = ?")->execute([$envio['tx_hash']]);
        echo "Reserva {$envio['reserva_id']}: la liquidación {$envio['tx_hash']} no salió; se vuelve a mandar.\n";
    }
}

//2. Las que no salieron: se mandan de nuevo
$sinMandar = $pdo->prepare(
    "SELECT DISTINCT reserva_id FROM pagos
     WHERE tipo IN (" . PagoDeLiquidacion::TIPOS . ") AND estado = 'pendiente' AND tx_hash IS NULL AND red = ?
       AND creado_en < UTC_TIMESTAMP() - INTERVAL 5 MINUTE
     ORDER BY reserva_id"
);
$sinMandar->execute([$firmador->red()]);
foreach ($sinMandar->fetchAll(\PDO::FETCH_COLUMN) as $reservaId) {
    try {
        $envio = PagoDeLiquidacion::mandar((int) $reservaId, $firmador);
        if ($envio !== null) {
            echo "Reserva $reservaId: liquidación mandada de nuevo ({$envio->txHash}).\n";
        }
    } catch (\Throwable $e) {
        $fallidas++;
        Log::error('No se pudo reintentar la liquidación', $e, ['reserva_id' => $reservaId]);
        echo "Reserva $reservaId: no se pudo mandar la liquidación ({$e->getMessage()}).\n";
    }
}

//3. Las pendientes que todavía no se mandaron
$nuevas = $pdo->query(
    "SELECT q.id, q.reserva_id FROM liquidaciones q
     WHERE q.estado = 'pendiente'
       AND NOT EXISTS (SELECT 1 FROM pagos g WHERE g.reserva_id = q.reserva_id AND g.tipo IN (" . PagoDeLiquidacion::TIPOS . "))
     ORDER BY q.id"
)->fetchAll();
if ($nuevas === []) {
    echo "No hay liquidaciones para pagar.\n";
}
foreach ($nuevas as $liquidacion) {
    $resultado = PagoDeLiquidacion::pagar((int) $liquidacion['id'], $firmador);
    echo "Reserva {$liquidacion['reserva_id']}: {$resultado['mensaje']}" . ($resultado['tx_hash'] ? " ({$resultado['tx_hash']})" : '') . ".\n";
    if ($resultado['codigo'] === 503) {
        $fallidas++;
    }
}

$pdo->query("SELECT RELEASE_LOCK('pagar_liquidaciones')");
exit($fallidas > 0 ? 1 : 0);
