<?php

//Corre juntas las tareas que tienen que pasar solas (decisión #54 de docs/pagos-decisiones.md). Es el comando de los
//dos servicios con cron de Railway (docs/despliegue.md, "Tareas programadas"):
//
//    php scripts/tareas-programadas.php frecuentes   cada 10 minutos
//    php scripts/tareas-programadas.php diarias      una vez por día, a las 6 de Argentina
//
//Cada tarea es un script de esta carpeta y corre en su propio proceso, en orden: si una falla, las otras corren igual.
//Todas son seguras de repetir (cada una tiene su candado o su índice único), así que una corrida de más no duplica nada.
//Termina con código 1 si alguna falló, para que Railway lo marque.
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

//Qué corre cada grupo, en orden: [script, argumentos]
const TAREAS = [
    'frecuentes' => [
        ['marcar-expiradas.php', []],                  //anota 'expirada' en las pendientes que no pagaron la seña a tiempo
        ['marcar-no-shows.php', ['--marcar']],         //anota 'no_show' en las confirmadas sin ingreso con el plazo vencido (#67)
        ['reintentar-devoluciones.php', []],           //confirma o vuelve a mandar las devoluciones pendientes
        ['generar-liquidaciones.php', ['--guardar']],  //calcula las liquidaciones: estadías con ingreso y canceladas con retención
        ['pagar-liquidaciones.php', []],               //las paga con liquidar() del contrato (después de las devoluciones)
    ],
    'diarias' => [
        ['cancelar-saldos-vencidos.php', []],           //primero cancela: así una cancelada no recibe recordatorio
        ['enviar-recordatorios.php', ['--enviar']],     //los mails del saldo (RF-25)
    ],
];

$grupo = $argv[1] ?? '';
if (!isset(TAREAS[$grupo])) {
    fwrite(STDERR, "Uso: php scripts/tareas-programadas.php frecuentes|diarias\n");
    exit(2);
}

$fallaron = [];
foreach (TAREAS[$grupo] as [$script, $argumentos]) {
    echo '[' . gmdate('Y-m-d H:i:s') . " UTC] $script\n";
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$script")
        . implode('', array_map(fn ($a) => ' ' . escapeshellarg($a), $argumentos));
    passthru($comando, $codigo);//la salida del script va directo a los logs de Railway
    if ($codigo !== 0) {
        $fallaron[] = "$script (código $codigo)";
    }
}

echo $fallaron === [] ? "Listo: $grupo sin errores.\n" : 'Fallaron: ' . implode(', ', $fallaron) . "\n";
exit($fallaron === [] ? 0 : 1);
