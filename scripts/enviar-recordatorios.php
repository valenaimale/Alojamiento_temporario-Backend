<?php

//Le recuerda por mail a cada huésped que tenga el saldo de su reserva sin pagar (RF-25).
//Pensado para un cron diario de Railway, pero se puede correr a mano. Desde la raíz del backend:
//
//    php scripts/enviar-recordatorios.php            muestra a quién le escribiría, sin mandar nada
//    php scripts/enviar-recordatorios.php --enviar    manda los mails
//
//Quién recibe un recordatorio y cada cuánto lo decide App\Validacion\CobroDelSaldo, con los parámetros
//dias_cobro_saldo, horas_entre_recordatorios y max_recordatorios_cobro, que administra el backoffice.
//Cada mail dice hasta qué día se puede pagar el saldo (dias_vencimiento_saldo, 3 por defecto) y que, pasado
//ese plazo, la reserva se cancela sola (decisión #23 de docs/pagos-decisiones.md). Una reserva ya cancelada,
//o a la que se le pasó ese plazo, no recibe más recordatorios.
//Correrlo dos veces el mismo día no manda nada dos veces: cada recordatorio se anota antes de mandarse
//y la base no deja anotar dos veces el mismo (índice único de reserva_id y número).
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

use App\Mail\RecordatorioSaldo;
use App\Validacion\CobroDelSaldo;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$enviar = in_array('--enviar', $argv, true);

$pendientes = CobroDelSaldo::pendientes();
if ($pendientes === []) {
    echo "No hay recordatorios para mandar.\n";
    exit(0);
}

echo count($pendientes) . " recordatorio(s) para mandar.\n";
if (!$enviar) {
    echo "Nada se mando: volve a correrlo con --enviar.\n";
}

$enviados = 0;
foreach ($pendientes as $recordatorio) {
    $detalle = "reserva {$recordatorio['reserva_id']} - {$recordatorio['unidad']} - "
        . "{$recordatorio['huesped']['nombre']} <{$recordatorio['huesped']['mail']}> - "
        . "debe {$recordatorio['debe']} - paga hasta el {$recordatorio['limite']} - "
        . "recordatorio {$recordatorio['numero']}"
        . ($recordatorio['ultimo'] ? ' (el ultimo)' : '');

    if (!$enviar) {
        echo "  [simulacion] $detalle\n";
        continue;
    }

    //Se anota primero y se manda después: si el mail falla, no se vuelve a intentar en esta corrida
    //(queda en el log) y el huésped no recibe tres mails iguales por un error de red.
    if (!CobroDelSaldo::anotar($recordatorio['reserva_id'], $recordatorio['numero'])) {
        echo "  [salteado]   $detalle (ya estaba anotado)\n";
        continue;
    }
    RecordatorioSaldo::enviar($recordatorio);
    $enviados++;
    echo "  [enviado]    $detalle\n";
}

if ($enviar) {
    echo "Recordatorios enviados: $enviados\n";
}
