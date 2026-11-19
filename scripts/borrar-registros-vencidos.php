<?php

//Borra los registros pendientes vencidos: los de quienes se registraron y no abrieron el enlace del mail en 24 horas
//(App\Validacion\RegistroPendiente). No hay nada que avisar: si la persona quiere la cuenta, se registra de nuevo.
//Se corre por consola, desde la raíz del backend, a mano o con las tareas diarias (tareas-programadas.php):
//
//    php scripts/borrar-registros-vencidos.php
//
//Es seguro de repetir: un único DELETE que solo toca los vencidos.

use App\Log\Log;
use App\Validacion\RegistroPendiente;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

try {
    $cantidad = RegistroPendiente::borrarVencidos();
    echo "Registros pendientes vencidos borrados: $cantidad.\n";
} catch (\Throwable $e) {
    Log::error('No se pudieron borrar los registros pendientes vencidos', $e);
    exit(1);
}
