<?php

//Anota 'expirada' en la base en las reservas pendientes a las que se les pasó el plazo para pagar la seña
//(minutos_bloqueo_reserva, 10 por defecto). Para el huésped ya se veían expiradas y sus noches ya estaban libres
//(VencimientoReserva::estadoVisible): esto deja la base igual que lo que se muestra.
//Se corre por consola, desde la raíz del backend, a mano o con las tareas frecuentes (tareas-programadas.php):
//
//    php scripts/marcar-expiradas.php
//
//Es seguro de repetir: un único UPDATE que solo toca pendientes vencidas.

use App\Log\Log;
use App\Validacion\VencimientoReserva;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

try {
    $cantidad = VencimientoReserva::marcarExpiradas();
    echo "Reservas expiradas sin pagar la seña: $cantidad.\n";
} catch (\Throwable $e) {
    Log::error('No se pudieron marcar las reservas expiradas', $e);
    exit(1);
}
