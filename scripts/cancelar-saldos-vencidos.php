<?php

//Cancela solas las reservas cuyo saldo no se pagó a tiempo (decisión #23 de docs/pagos-decisiones.md).
//Pasada la fecha_cobro, el huésped tiene dias_vencimiento_saldo días (3 por defecto) para pagar el saldo, o hasta el
//día del ingreso si llega antes (CobroDelSaldo::limiteDePago: el MISMO plazo que anuncian el mail de la seña y los
//recordatorios). Pasado ese día sin pagar, la reserva se cancela como una cancelación del huésped con el tramo de
//menos de 15 días: se retiene ese % de la seña y se le devuelve el resto (CancelacionConDevolucion).
//Se corre por consola, desde la raíz del backend, a mano o con un cron de Railway (una vez por día alcanza; más seguido
//no hace daño):
//
//    php scripts/cancelar-saldos-vencidos.php
//
//Para no cancelar dos veces: un candado de MySQL (GET_LOCK) y, adentro de cada cancelación, la reserva trabada con
//FOR UPDATE y vuelta a mirar (si el huésped pagó el saldo justo antes, ya no se cancela).
//No toca las reservas viejas sin monto_faltante ni fecha_cobro (#26), ni las que ya tienen el ingreso registrado.

use App\Database\Conexion;
use App\Log\Log;
use App\Validacion\CancelacionConDevolucion;
use App\Validacion\CobroDelSaldo;
use App\Validacion\ValidadorFechas;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$pdo = Conexion::obtener();
if ((int) $pdo->query("SELECT GET_LOCK('cancelar_saldos_vencidos', 0)")->fetchColumn() !== 1) {
    echo "Ya hay otra copia del script corriendo: no hago nada.\n";
    exit(0);
}

$hoy = ValidadorFechas::hoy()->format('Y-m-d');

//Candidatas: confirmadas, con saldo por pagar y sin ingreso registrado. El plazo exacto se calcula en PHP
//con limiteDePago, para que sea el mismo que vio el huésped.
$consulta = $pdo->prepare(
    "SELECT r.id, r.fecha_cobro, r.fecha_ingreso FROM reservas r
     WHERE r.estado = 'confirmada' AND r.monto_faltante > 0 AND r.fecha_cobro IS NOT NULL AND r.fecha_cobro < ?
       AND NOT EXISTS (SELECT 1 FROM estadias e WHERE e.reserva_id = r.id)
     ORDER BY r.id"
);
$consulta->execute([$hoy]);

//Si la reserva sigue igual cuando se la traba: confirmada, con saldo, sin ingreso y con el plazo vencido
$sigueVencida = function (array $reserva) use ($pdo, $hoy): bool {
    if ($reserva['monto_faltante'] === null || bccomp($reserva['monto_faltante'], '0', 2) <= 0 || $reserva['fecha_cobro'] === null) {
        return false;
    }
    if (CobroDelSaldo::limiteDePago($reserva['fecha_cobro'], $reserva['fecha_ingreso']) >= $hoy) {
        return false;
    }
    $estadia = $pdo->prepare('SELECT 1 FROM estadias WHERE reserva_id = ?');
    $estadia->execute([$reserva['id']]);
    return $estadia->fetchColumn() === false;
};

$canceladas = 0;
$fallidas = 0;
foreach ($consulta->fetchAll() as $fila) {
    $limite = CobroDelSaldo::limiteDePago($fila['fecha_cobro'], $fila['fecha_ingreso']);
    if ($limite >= $hoy) {
        continue;//todavía está a tiempo
    }
    try {
        $resultado = CancelacionConDevolucion::cancelar((int) $fila['id'], $sigueVencida, $limite);
        if ($resultado === null) {
            echo "Reserva {$fila['id']}: cambió mientras la cancelaba (¿pagó el saldo?), no la toco.\n";
            continue;
        }
        $canceladas++;
        $devolucion = $resultado['devolucion'];
        $estado = match (true) {
            $resultado['pago_id'] === null           => 'sin devolución',
            $resultado['envio']?->confirmada === true => "devolución de \${$devolucion['devolucion']} confirmada ({$resultado['envio']->txHash})",
            default                                   => "devolución de \${$devolucion['devolucion']} pendiente (la confirma reintentar-devoluciones.php)",
        };
        echo "Reserva {$fila['id']}: cancelada, el saldo vencía el $limite. Retención \${$devolucion['retencion']}, $estado.\n";
    } catch (\Throwable $e) {
        $fallidas++;
        Log::error('No se pudo cancelar la reserva con el saldo vencido', $e, ['reserva_id' => $fila['id']]);
        echo "Reserva {$fila['id']}: no se pudo cancelar ({$e->getMessage()}).\n";
    }
}

$pdo->query("SELECT RELEASE_LOCK('cancelar_saldos_vencidos')");
echo $canceladas === 0 && $fallidas === 0 ? "No hay reservas con el saldo vencido.\n" : "Canceladas: $canceladas. Con error: $fallidas.\n";
exit($fallidas > 0 ? 1 : 0);
