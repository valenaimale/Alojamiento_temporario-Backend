<?php
//En este archivo se carga toda la configuracion de la aplicacion.


require __DIR__ . '/../vendor/autoload.php';
//autoload.php carga de forma automatica todas las
//clases declaradas en el composer

use App\Router\Router;

$router = new Router();//este objeto va a redirigir

$request = new Request();//el objeto request va a tener los datos de la request.

$router->cargar_rutas();//aca se van a incializar todas las rutas posible
//de la aplicacion
$router->direct($request);//el router va a ejecutar el metodo correspondiente
//del controlador correspondiente que sepa resolver la request.