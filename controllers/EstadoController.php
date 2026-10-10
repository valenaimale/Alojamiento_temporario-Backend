<?php

namespace App\Controllers;

use App\Config\Config;

//GET /estado: muestra qué réplica del backend atendió la petición.
//Sirve para la demo de escalado horizontal: detrás del balanceador, "replica" va alternando.
class EstadoController
{
    public function estado()
    {
        http_response_code(200);
        echo json_encode([
            'ok'       => 'API funcionando',
            'replica'  => gethostname(),//en Docker es el hostname de cada contenedor (backend1, backend2)
            'sesiones' => getenv('SESIONES_DRIVER') ?: Config::obtener('sesiones.driver'),
        ]);
    }
}
