<?php

//Pasa a 'no_show' las reservas confirmadas a las que se les pasó el plazo para registrar el ingreso (24 hs desde el
//horario de check-in de la unidad, decisión #67 de docs/pagos-decisiones.md), y les avisa por mail al huésped y al
//propietario. Desde ahí la reserva se trata como una cancelación del huésped con todo pagado: se retiene lo pagado,
//el propietario tiene 48 hs para dar una excepción y lo retenido se liquida.
//Se corre por consola, desde la raíz del backend, a mano o con las tareas frecuentes (tareas-programadas.php),
//SIEMPRE antes de generar-liquidaciones.php, así lo retenido se liquida en la misma corrida:
//
//    php scripts/marcar-no-shows.php             muestra qué marcaría, sin tocar nada ni mandar mails
//    php scripts/marcar-no-shows.php --marcar    las marca y avisa
//
//Es seguro de repetir: solo toca reservas confirmadas sin ingreso, así que una ya marcada no se vuelve a marcar
//y a nadie se le avisa dos veces.
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

use App\Log\Log;
use App\Validacion\NoShow;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$marcar = in_array('--marcar', $argv, true);

try {
    $reservas = $marcar ? NoShow::marcar() : NoShow::vencidas();
    if ($reservas === []) {
        echo "No hay reservas sin ingreso con el plazo vencido.\n";
        exit(0);
    }

    //una linea por reserva, para poder seguirlas en los logs de Railway
    foreach ($reservas as $r) {
        $etiqueta = $marcar ? '[no-show]   ' : '[simulacion]';
        echo "  $etiqueta reserva {$r['id']} - {$r['unidad_nombre']} - {$r['huesped_nombre']}"
            . " - ingreso {$r['fecha_ingreso']} {$r['hora_check_in']}\n";
    }

    echo $marcar
        ? 'Reservas marcadas como no-show: ' . count($reservas) . "\n"
        : count($reservas) . " reserva(s) con el plazo vencido. Nada se marco: volve a correrlo con --marcar.\n";
} catch (\Throwable $e) {
    Log::error('No se pudieron marcar los no-shows', $e);
    exit(1);
}
