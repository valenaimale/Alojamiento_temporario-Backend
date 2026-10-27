<?php

namespace App\Controllers;

use App\Database\Conexion;

//Las localidades donde opera la plataforma: una lista cerrada (US3.2).
//La usan la búsqueda (huésped, con o sin sesión) y el alta de una unidad (propietario).
class LocalidadesController
{
    //GET /localidades: las activas, ordenadas por provincia y nombre (el front las agrupa por provincia).
    //Es pública: la búsqueda también se usa sin sesión.
    public function listar()
    {
        $localidades = Conexion::obtener()->query(
            'SELECT id, nombre, provincia FROM localidades WHERE activa = 1 ORDER BY provincia, nombre'
        )->fetchAll();

        foreach ($localidades as &$localidad) {
            $localidad['id'] = (int) $localidad['id'];//MySQL lo devuelve como texto
        }

        http_response_code(200);
        echo json_encode(['localidades' => $localidades]);
    }
}
