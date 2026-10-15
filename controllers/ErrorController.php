<?php

namespace App\Controllers;

class ErrorController{

    public function notFound()
    {
        http_response_code(404);
        echo json_encode(['error' => 'Ruta no encontrada']);
    }

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
