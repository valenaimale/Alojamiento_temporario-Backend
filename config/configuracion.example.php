<?php
//Plantilla de configuración. Copiarla como config/configuracion.php (ignorado por git) y completar los datos.
//Los datos de conexión a MySQL siguen estando en phinx.php.
return [
    'app' => [
        'url_front' => 'http://localhost:5500',   //sin barra al final
        'url_back'  => 'http://localhost:8000',
    ],
    'sesiones' => [
        'driver' => 'archivos',                   //'archivos' (por defecto) o 'redis' (Tarea 3)
        'redis'  => [
            'host'              => '127.0.0.1',
            'port'              => 6379,
            'prefijo'           => 'alojamiento:sesion:',
            'duracion_segundos' => 7200,
        ],
    ],
    'smtp' => [                                   //Tarea 2. Valores para Mailpit en desarrollo
        'host'             => '127.0.0.1',
        'port'             => 1025,
        'usuario'          => '',
        'contrasenia'      => '',
        'cifrado'          => '',                 //'' (Mailpit), 'tls' o 'ssl'
        'remitente'        => 'no-responder@alojamiento.local',
        'nombre_remitente' => 'Alojamiento temporario',
    ],
    'google' => [                                 //Tarea 4
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => 'http://localhost:8000/oauth/google/callback',
    ],
];
