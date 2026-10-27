<?php

namespace App\Controllers;

//Las respuestas de error que usa el Router cuando no puede atender una petición:
//ruta inexistente (404), error no controlado (500) y datos con una forma inválida (422).
class ErrorController{

    //La ruta pedida no existe
    public function notFound()
    {
        http_response_code(404);
        echo json_encode(['error' => 'Ruta no encontrada']);
    }

    //Algo falló en el servidor: mensaje genérico, sin detalles internos (el detalle queda en el log)
    public function internalError()
    {
        http_response_code(500);
        echo json_encode(['error' => 'Estamos con algunos inconvenientes. Vuelva a intentar en unos instantes...']);
    }

    //Los datos no tienen la forma esperada (ver App\Exceptions\DatosInvalidosException)
    public function datosInvalidos()
    {
        http_response_code(422);
        echo json_encode(['error' => 'Los datos enviados no son válidos']);
    }
}
