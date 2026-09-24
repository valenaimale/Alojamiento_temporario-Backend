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
use App\Request\Request;

//---------- CORS ----------
//El front corre en otro origen (otro puerto), asi que el navegador bloquea
//sus peticiones salvo que el back lo autorice con estos headers.
//Live Server puede abrir el front como localhost o como 127.0.0.1: son origenes distintos.
$origenesPermitidos = ['http://localhost:5500', 'http://127.0.0.1:5500'];
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origen, $origenesPermitidos, true)) {
    header("Access-Control-Allow-Origin: $origen");//origen autorizado a leer las respuestas
    header('Vary: Origin');//la respuesta depende del origen que la pidio
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');//metodos permitidos
header('Access-Control-Allow-Headers: Content-Type');//headers que el front puede enviar

//Antes de un POST con JSON, el navegador manda una peticion OPTIONS (preflight)
//para preguntar si tiene permiso. Se responde con los headers de arriba y se corta aca.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
//---------- fin CORS ----------

//Todas las respuestas del back son JSON.
header('Content-Type: application/json; charset=utf-8');

$router = new Router();//este objeto va a redirigir

$request = new Request();//el objeto request va a tener los datos de la request.

$router->cargar_rutas();//aca se van a incializar todas las rutas posible
//de la aplicacion
$router->direct($request);//el router va a ejecutar el metodo correspondiente
//del controlador correspondiente que sepa resolver la request.