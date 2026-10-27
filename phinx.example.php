<?php

//Plantilla de phinx.php (la configuración de la base de datos para Phinx y para Conexion en desarrollo).
//Se copia como phinx.php (ignorado por git) y se completan el usuario y la contraseña de MySQL de cada uno.
return
[
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [
            'adapter' => 'mysql',
            'host' => 'localhost',
            'name' => 'alojamiento',
            'user' => 'root',
            'pass' => '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
        'development' => [
            'adapter' => 'mysql',
            'host' => 'localhost',
            'name' => 'alojamiento',
            'user' => 'root',
            'pass' => '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ],
        'testing' => [
            'adapter' => 'mysql',
            'host' => 'localhost',
            'name' => 'alojamiento',
            'user' => 'root',
            'pass' => '',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ]
    ],
    'version_order' => 'creation'
];
