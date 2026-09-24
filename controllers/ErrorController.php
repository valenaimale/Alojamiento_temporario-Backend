<?php

namespace App\Controllers;

class ErrorController{
   
    public function notFound()
    {
        http_response_code(404);
        //mostrar html de error 404
    }

    public function internalError()
    {
        http_response_code(500);
        //mostrar html de error 500
    }
}
