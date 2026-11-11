<?php

//Calcula y guarda las liquidaciones de las estadías en las que el huésped ya ingresó, y de las reservas canceladas
//en las que se retuvo parte de la seña (decisión #57), que todavía no se liquidaron (RF-09, RF-13 y RF-19).
//Hace lo mismo que POST /liquidaciones/generar, pero por consola, para poder dejarlo en un cron de Railway y no
//depender de que alguien entre al backoffice. Pagarlas (mandar el ETH) es el paso siguiente: pagar-liquidaciones.php.
//
//Desde la raíz del backend:
//
//    php scripts/generar-liquidaciones.php            muestra qué liquidaría, sin guardar nada
//    php scripts/generar-liquidaciones.php --guardar   las calcula y las guarda
//
//Es idempotente: una estadía ya liquidada no se vuelve a liquidar (lo garantiza el índice único de reserva_id),
//así que se puede correr todos los días sin miedo a duplicar.
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

use App\Database\Conexion;
use App\Validacion\Liquidacion;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$guardar = in_array('--guardar', $argv, true);

$reservas = Liquidacion::reservasSinLiquidar();
if ($reservas === []) {
    echo "No hay estadias sin liquidar.\n";
    exit(0);
}

echo count($reservas) . " estadia(s) sin liquidar.\n";
if (!$guardar) {
    echo "Nada se guardo: volve a correrlo con --guardar.\n";
}

$consulta = Conexion::obtener()->prepare(
    'SELECT u.nombre AS unidad, h.nombre AS huesped, r.fecha_ingreso, r.fecha_egreso, r.importe_total
     FROM reservas r
     JOIN unidades u ON u.id = r.unidad_id
     JOIN usuarios h ON h.id = r.huesped_id
     WHERE r.id = ?'
);

$generadas = 0;
foreach ($reservas as $reservaId) {
    $consulta->execute([$reservaId]);
    $reserva = $consulta->fetch();
    $detalle = "reserva $reservaId - {$reserva['unidad']} - {$reserva['huesped']} - "
        . "{$reserva['fecha_ingreso']} al {$reserva['fecha_egreso']} - total {$reserva['importe_total']}";

    if (!$guardar) {
        echo "  [simulacion] $detalle\n";
        continue;
    }

    $id = Liquidacion::generar($reservaId);
    if ($id === null) {
        echo "  [salteada]   $detalle (ya estaba liquidada)\n";
        continue;
    }
    $generadas++;
    echo "  [liquidada]  $detalle -> liquidacion $id\n";
}

if ($guardar) {
    echo "Liquidaciones generadas: $generadas\n";
}
