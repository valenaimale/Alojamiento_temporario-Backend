<?php

//Arranque de PHPUnit (lo indica phpunit.xml): carga el autoload de Composer, que incluye las clases
//de la aplicación (App\...) y las de los tests (Tests\...).
//La base y el servidor de prueba no se preparan acá: los prepara Tests\Soporte\EntornoDePrueba solo cuando
//se corre algún test de integración, así los unitarios se pueden correr sin MySQL.

define('RAIZ', dirname(__DIR__));

require RAIZ . '/vendor/autoload.php';
