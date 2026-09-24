<?php

namespace App\Router;
use App\Request\Request;
use App\Exceptions\RouteNotFoundException;

class Router {
    private $routes = [];
    public string $notFound = 'not_found';
    public string $internalError = 'internal_error';
    
    public function __construct(){
        $this->register($this->notFound,  'ErrorController@notFound');
        $this->register($this->internalError,  'ErrorController@internalError');
    }

    public function call($controller, $action){
        $controller_name = "App\\Controllers\\{$controller}";
        $objController = new $controller_name;
        $objController->$action();
    }

    public function getController($clavemethodmaspath){
        if(!array_key_exists($clavemethodmaspath, $this->routes)){
            throw new RouteNotFoundException("No existe ruta para este path");
        }
        return $valorControllerYAction = explode("@", $this->routes[$clavemethodmaspath]);
    }    

    public function direct(Request $request){
        $clavemethodmaspath = $request->route();//obtengo de la request el method_http + la url solicitada por el usuario

        try{
            $valorcontrolleraction = $this->getController($clavemethodmaspath);//intento obtener el controlador + la accion a realizar por el mismo
            $this->call($valorcontrolleraction[0], $valorcontrolleraction[1]);
        }
        catch (RouteNotFoundException $e){
            $valorcontrolleraction = $this->getController($this->notFound);//si salta la exception de que no se encontro la ruta, intenta obtener
            //controlador + accion a realizar para el caso de una ruta no encontrada
            $this->call($valorcontrolleraction[0], $valorcontrolleraction[1]);
        }

        catch (Exception $e){
            $valorcontrolleraction = $this->getController($this->internalError);//si salta la exception, intenta obtener
            //controlador + accion a realizar para el caso de exception
            $this->call($valorcontrolleraction[0], $valorcontrolleraction[1]);
        }
    }

    //cambiarlas
    //cargo las rutas (siempre van a estar hardcodeadas)
    public function cargar_rutas(){
        $this->register('GET@/', 'HomeController@homeSinLogg');
    }
    //Registra las rutas en el ROUTER
    public function register($method_http_y_path, $controller_y_action){
        $this->routes[$method_http_y_path] = $controller_y_action;
    }
}    