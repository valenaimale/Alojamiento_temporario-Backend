<?php

//Borra las reservas de prueba de cuando los pagos eran simulados (decisión #53 de docs/pagos-decisiones.md), con sus
//pagos, recordatorios, liquidaciones, libro de huéspedes y estadías. Se corre UNA vez, antes de activar los pagos
//reales (PAGOS_VERIFICADOR y PAGOS_FIRMADOR en 'blockchain'). Qué cuenta como simulada: App\Validacion\ReservasSimuladas.
//
//Desde la raíz del backend (en producción, adentro de `railway ssh`):
//
//    php scripts/borrar-reservas-simuladas.php            muestra cuáles borraría, sin borrar nada
//    php scripts/borrar-reservas-simuladas.php --borrar   las borra (en una sola transacción)
//
//Borrar no se puede deshacer: primero correrlo sin --borrar y revisar la lista.
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

use App\Config\Config;
use App\Validacion\ReservasSimuladas;
use App\Validacion\VerificadorBlockchain;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$borrar = in_array('--borrar', $argv, true);
$redReal = (int) Config::obtener('blockchain.red', VerificadorBlockchain::RED_BASE_SEPOLIA);

$reservas = ReservasSimuladas::listar($redReal);
if ($reservas === []) {
    echo "No hay reservas simuladas.\n";
    exit(0);
}

echo count($reservas) . " reserva(s) simulada(s):\n";
foreach ($reservas as $reserva) {
    echo "  #{$reserva['id']} {$reserva['estado']}, {$reserva['unidad']}, del {$reserva['fecha_ingreso']} al {$reserva['fecha_egreso']},"
        . " huésped {$reserva['huesped']}, {$reserva['pagos']} pago(s)\n";
}

if (!$borrar) {
    echo "No se borró nada: revisá la lista y volvé a correrlo con --borrar.\n";
    exit(0);
}

//si el borrado falla, el motivo se imprime acá: es un script de una sola corrida y hay que poder leer qué pasó
//sin ir a buscar el log (la transacción ya volvió atrás, así que no quedó nada a medias)
try {
    $borradas = ReservasSimuladas::borrar(array_column($reservas, 'id'));
} catch (\Throwable $e) {
    echo 'Falló el borrado, no se borró ninguna: ' . $e->getMessage() . "\n";
    exit(1);
}
echo "Se borraron $borradas reserva(s) con todos sus datos.\n";
