<?php

namespace App\Controllers;

use App\Request\Respuesta;

//Las respuestas de error que usa el Router cuando no puede atender una petición:
//ruta inexistente (404), error no controlado (500) y datos con una forma inválida (422).
class ErrorController{

    //La ruta pedida no existe
    public function notFound()
    {
        Respuesta::json(['error' => 'Ruta no encontrada'], 404);
    }

    //Algo falló en el servidor: mensaje genérico, sin detalles internos (el detalle queda en el log)
    public function internalError()
    {
        Respuesta::json(['error' => 'Estamos con algunos inconvenientes. Vuelva a intentar en unos instantes...'], 500);
    }

    //Los datos no tienen la forma esperada (ver App\Exceptions\DatosInvalidosException)
    public function datosInvalidos()
    {
        Respuesta::json(['error' => 'Los datos enviados no son válidos'], 422);
    }
}
