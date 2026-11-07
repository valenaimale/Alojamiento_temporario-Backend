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
        'cookie_segura' => '0',                   //'1' en producción (HTTPS): la cookie de sesión solo viaja cifrada
        'redis'  => [
            'host'              => '127.0.0.1',
            'port'              => 6379,
            'prefijo'           => 'alojamiento:sesion:',
            'duracion_segundos' => 7200,
        ],
    ],
    'mail' => [
        'transporte' => 'smtp',                   //'smtp' (Mailpit en desarrollo) o 'brevo' (producción: Railway bloquea el SMTP)
    ],
    'brevo' => [
        'api_key' => '',                          //solo con transporte 'brevo'; en producción va en la variable BREVO_API_KEY
    ],
    'smtp' => [                                   //Tarea 2. Valores para Mailpit en desarrollo. remitente y nombre_remitente valen también para Brevo
        'host'             => '127.0.0.1',
        'port'             => 1025,
        'usuario'          => '',
        'contrasenia'      => '',
        'cifrado'          => '',                 //'' (Mailpit), 'tls' o 'ssl'
        'remitente'        => 'no-responder@alojamiento.local',
        'nombre_remitente' => 'Alojamiento temporario',
    ],
    'pagos' => [                                  //L2-28
        'verificador' => 'simulado',              //cómo se verifican los pagos: 'simulado' o 'blockchain' (PAGOS_VERIFICADOR)
        'firmador'    => 'simulado',              //cómo firma la plataforma devolver y liquidar: 'simulado' o 'blockchain' (PAGOS_FIRMADOR)
    ],
    'blockchain' => [                             //solo con 'blockchain'
        'rpc_url' => 'https://sepolia.base.org',  //BLOCKCHAIN_RPC_URL
        'red'     => 84532,                       //BLOCKCHAIN_RED: Base Sepolia
    ],
    'contrato' => [
        'direccion' => '0xF9d86D0e7c3D2520103fD11e64174C9e6BcFc0F4', //CONTRATO_DIRECCION: PasarelaPago en Base Sepolia
    ],
    'plataforma' => [
        'clave_privada' => '',                    //PLATAFORMA_CLAVE_PRIVADA: NUNCA en el repo, solo como variable de Railway
    ],
    'google' => [                                 //Tarea 4
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => 'http://localhost:8000/oauth/google/callback',
    ],
];
