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

        catch (\Throwable $e){//Throwable atrapa tanto Exception como Error (ej: clase o metodo inexistente)
            error_log($e);//escribe el error real en la terminal del servidor (php -S); al usuario solo le llega el mensaje generico
            $valorcontrolleraction = $this->getController($this->internalError);//si salta la exception, intenta obtener
            //controlador + accion a realizar para el caso de exception
            $this->call($valorcontrolleraction[0], $valorcontrolleraction[1]);
        }
    }

    //cargo las rutas (siempre van a estar hardcodeadas)
    //Cada tarea agrega sus rutas SOLO dentro de su bloque: así los PRs no chocan.
    public function cargar_rutas(){
        // ---------- Base (Fase 0) ----------
        $this->register('POST@/registrarse', 'RegistroController@registrar');
        $this->register('POST@/iniciar-sesion', 'InicioSesionController@iniciaSesion');
        $this->register('POST@/cerrar-sesion', 'InicioSesionController@cierraSesion');
        $this->register('GET@/sesion', 'InicioSesionController@dameSesion');
        // ---------- fin Base ----------

        // ---------- Tarea 1: cuentas y roles ----------
        $this->register('POST@/hacerse-propietario', 'PropietarioController@hacerse');
        $this->register('GET@/datos-fiscales', 'PropietarioController@datosFiscales');
        // ---------- fin Tarea 1 ----------

        // ---------- Tarea 2: verificación de mail y backoffice ----------
        // (la Tarea 2 agrega sus rutas acá)
        // ---------- fin Tarea 2 ----------

        // ---------- Tarea 3: sesiones escalables ----------
        // (la Tarea 3 agrega sus rutas acá)
        // ---------- fin Tarea 3 ----------

        // ---------- Tarea 4: OAuth ----------
        // (la Tarea 4 agrega sus rutas acá)
        // ---------- fin Tarea 4 ----------
    }
    //Registra las rutas en el ROUTER
    public function register($method_http_y_path, $controller_y_action){
        $this->routes[$method_http_y_path] = $controller_y_action;
    }
}    