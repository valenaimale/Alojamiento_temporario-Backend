<?php

//Borra TODAS las reservas (con sus pagos, liquidaciones, estadías, libro de huéspedes, recordatorios y grupos), para
//pasar al contrato nuevo de la reserva grupal (decisión #76 de docs/pagos-decisiones.md). Se corre UNA vez, justo antes
//de cambiar CONTRATO_DIRECCION en Railway. Qué borra y por qué: App\Validacion\ReservasDePrueba.
//
//Desde la raíz del backend (en producción, adentro de `railway ssh`):
//
//    php scripts/borrar-reservas-de-prueba.php                                    muestra qué borraría, sin borrar nada
//    php scripts/borrar-reservas-de-prueba.php --borrar --son-todas-de-prueba     las borra
//
//Borrar no se puede deshacer: primero correrlo sin opciones y revisar el resumen. Pide las dos opciones a propósito,
//para que nadie lo corra por error creyendo que es el de las simuladas (borrar-reservas-simuladas.php).
//No toca unidades, usuarios, unidades grupales, precios ni bloqueos.
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

use App\Validacion\ReservasDePrueba;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$resumen = ReservasDePrueba::resumen();
if ($resumen['reservas'] === 0) {
    echo "No hay reservas.\n";
    exit(0);
}

echo "Se borrarían {$resumen['reservas']} reserva(s):\n";
foreach ($resumen['por_estado'] as $estado => $cantidad) {
    echo "  $estado: $cantidad\n";
}
echo "Con ellas: {$resumen['pagos']} pago(s), {$resumen['liquidaciones']} liquidación(es) y {$resumen['grupos']} grupo(s) de reservas grupales.\n";
echo "No se tocan unidades, usuarios, unidades grupales, precios ni bloqueos.\n";

if (!in_array('--borrar', $argv, true) || !in_array('--son-todas-de-prueba', $argv, true)) {
    echo "No se borró nada: si son todas de prueba, volvé a correrlo con --borrar --son-todas-de-prueba.\n";
    exit(0);
}

//si el borrado falla, el motivo se imprime acá: las reservas se borran en una transacción, así que no queda nada a medias
try {
    $borradas = ReservasDePrueba::borrarTodas();
} catch (\Throwable $e) {
    echo 'Falló el borrado, no se borró ninguna reserva: ' . $e->getMessage() . "\n";
    exit(1);
}
echo "Se borraron $borradas reserva(s) con todos sus datos.\n";
