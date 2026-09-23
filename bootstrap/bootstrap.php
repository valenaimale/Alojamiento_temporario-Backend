<?php
//En este archivo se carga toda la configuracion de la aplicacion.


require __DIR__ . '/../vendor/autoload.php';
//autoload.php carga de forma automatica todas las
//clases declaradas en el composer, evitando tener que hacer
//un require __DIR__ . '/../router/Router.php' distinto
//(ya que es relativo a la carpeta donde estes parado)
//por cada vez que se quiera usar el router en un archivo
//con autoload, todos los archivos usan "use App\Router\Router;"
//para utilizar el router, sin importar en que carpeta esten parados.
//esto es gracias a que el autoload mapea todos los 
//namespaces con su respectiva ruta (este mapeo esta especificado
//en composer.json), es decir, ya hace el 
// 'require __DIR__ ....' de cada clase especificada 
//en el composer.json internamente. El 'require __DIR__ . '/../vendor/autoload.php';'
//se hace solo una vez en el bootstrap y luego las clases/scripts
//php van a poder utilizar los namespaces que aparecen en composer.json
//mediante el use.

use App\Router\Router;

$router = new Router();//este objeto va a redirigir

$request = new Request();//el objeto request va a tener los datos de la request.

$router->cargar_rutas();//aca se van a incializar todas las rutas posible
//de la aplicacion
$router->direct($request);//el router va a ejecutar el metodo correspondiente
//del controlador correspondiente que sepa resolver la request.