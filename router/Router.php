<?php

namespace App\Router;
use App\Request\Request;
use App\Exceptions\RouteNotFoundException;
use App\Exceptions\DatosInvalidosException;
use App\Log\Log;

//El Router recibe cada petición, busca qué controlador y qué método la atienden (por método HTTP y ruta)
//y lo ejecuta. Si algo falla, responde el error que corresponde (404, 422 o 500) y lo deja en el log.
class Router {
    private $routes = [];
    public string $notFound = 'not_found';
    public string $internalError = 'internal_error';
    public string $datosInvalidos = 'datos_invalidos';
    
    //Registra las rutas internas de los errores (no las pide nadie desde afuera: las usa direct())
    public function __construct(){
        $this->register($this->notFound,  'ErrorController@notFound');
        $this->register($this->internalError,  'ErrorController@internalError');
        $this->register($this->datosInvalidos,  'ErrorController@datosInvalidos');
    }

    //Crea el controlador y ejecuta su método
    public function call($controller, $action){
        $controller_name = "App\\Controllers\\{$controller}";
        $objController = new $controller_name;
        $objController->$action();
    }

    //Devuelve [controlador, método] de una ruta, o lanza RouteNotFoundException si no existe
    public function getController($clavemethodmaspath){
        if(!array_key_exists($clavemethodmaspath, $this->routes)){
            throw new RouteNotFoundException("No existe ruta para este path");
        }
        return $valorControllerYAction = explode("@", $this->routes[$clavemethodmaspath]);
    }    

    //Atiende la petición: ejecuta el controlador de la ruta y, si algo falla, la respuesta de error que corresponda
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

        catch (DatosInvalidosException $e){//los datos no tienen la forma esperada: es un error del cliente (422), no del servidor
            $valorcontrolleraction = $this->getController($this->datosInvalidos);
            $this->call($valorcontrolleraction[0], $valorcontrolleraction[1]);
        }

        catch (\Throwable $e){//Throwable atrapa tanto Exception como Error (ej: clase o metodo inexistente)
            Log::error('Error no controlado', $e);//el error real queda en el log (JSON); al usuario solo le llega el mensaje generico
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
        $this->register('POST@/verificar-mail', 'VerificacionMailController@verificar');
        $this->register('POST@/reenviar-verificacion', 'VerificacionMailController@reenviar');
        $this->register('GET@/backoffice/usuarios', 'BackofficeController@listar');
        $this->register('GET@/backoffice/usuario', 'BackofficeController@detalle');
        $this->register('POST@/backoffice/usuario/estado', 'BackofficeController@cambiarEstado');
        // ---------- fin Tarea 2 ----------

        // ---------- Tarea 3: sesiones escalables ----------
        $this->register('GET@/estado', 'EstadoController@estado');
        // ---------- fin Tarea 3 ----------

        // ---------- Tarea 4: OAuth ----------
        $this->register('GET@/oauth/google', 'OAuthController@iniciarGoogle');
        $this->register('GET@/oauth/google/callback', 'OAuthController@callbackGoogle');
        $this->register('POST@/completar-datos', 'OAuthController@completarDatos');
        // ---------- fin Tarea 4 ----------

        // ---------- Épicas 2 y 3: base de las unidades ----------
        $this->register('GET@/localidades', 'LocalidadesController@listar');
        $this->register('GET@/unidades/resumen', 'ResumenUnidadController@resumen');
        // ---------- fin base de las unidades ----------

        // ---------- PR 2: alta y datos de la unidad (US2.1, US2.6) ----------
        $this->register('POST@/unidades/crear', 'UnidadesController@crear');
        $this->register('GET@/unidades/mias', 'UnidadesController@mias');
        $this->register('GET@/unidades/datos', 'UnidadesController@datos');
        $this->register('POST@/unidades/editar', 'UnidadesController@editar');
        $this->register('POST@/unidades/deposito', 'UnidadesController@deposito');
        $this->register('POST@/unidades/fotos/agregar', 'FotosUnidadController@agregar');
        $this->register('POST@/unidades/fotos/quitar', 'FotosUnidadController@quitar');
        $this->register('POST@/unidades/fotos/principal', 'FotosUnidadController@principal');
        // ---------- fin PR 2 ----------

        // ---------- PR 3: condiciones comerciales (US2.7, US2.8, US2.9) ----------
        $this->register('GET@/unidades/temporadas', 'TemporadasController@listar');
        $this->register('POST@/unidades/temporadas/crear', 'TemporadasController@crear');
        $this->register('POST@/unidades/temporadas/editar', 'TemporadasController@editar');
        $this->register('POST@/unidades/temporadas/borrar', 'TemporadasController@borrar');
        $this->register('GET@/unidades/precios', 'PreciosController@ver');
        $this->register('POST@/unidades/precios/cargar', 'PreciosController@cargar');
        $this->register('GET@/unidades/politica', 'PoliticaController@ver');
        $this->register('POST@/unidades/politica', 'PoliticaController@guardar');
        // ---------- fin PR 3 ----------

        // ---------- PR 4: publicación y bloqueos (US3.1, US3.3) ----------
        $this->register('POST@/unidades/publicar', 'PublicacionController@publicar');
        $this->register('POST@/unidades/pausar', 'PublicacionController@pausar');
        $this->register('GET@/unidades/bloqueos', 'BloqueosController@listar');
        $this->register('POST@/unidades/bloqueos/crear', 'BloqueosController@crear');
        $this->register('POST@/unidades/bloqueos/liberar', 'BloqueosController@liberar');
        // ---------- fin PR 4 ----------

        // ---------- PR 5: búsqueda y detalle (US3.2) ----------
        // ---------- fin PR 5 ----------

        // ---------- PR 6: gestión de la unidad (US2.2, US2.3, US2.4, US2.5) ----------
        // ---------- fin PR 6 ----------
    }
    //Registra las rutas en el ROUTER
    public function register($method_http_y_path, $controller_y_action){
        $this->routes[$method_http_y_path] = $controller_y_action;
    }
}    