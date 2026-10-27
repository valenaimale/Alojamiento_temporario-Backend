<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Validacion\ReglasPublicacion;

//La página central de una unidad (configurar-unidad.html): su nombre, su estado y qué le falta para publicarse.
//Desde ahí se entra a cada sección: datos y fotos, gestión, temporadas y precios, política, publicación y bloqueos.
class ResumenUnidadController
{
    //GET /unidades/resumen?id=5 — el propietario o el administrador asignado
    public function resumen()
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador($_GET['id'] ?? '');//si no puede, responde y corta acá

        $consulta = Conexion::obtener()->prepare('SELECT nombre, provincia FROM localidades WHERE id = ?');
        $consulta->execute([$unidad['localidad_id']]);
        $localidad = $consulta->fetch();

        http_response_code(200);
        echo json_encode(['unidad' => [
            'id'                   => (int) $unidad['id'],
            'nombre'               => $unidad['nombre'],
            'localidad'            => $localidad['nombre'],
            'provincia'            => $localidad['provincia'],
            'estado_publicacion'   => $unidad['estado_publicacion'],
            'disponible'           => (bool) $unidad['disponible'],//MySQL lo devuelve como 1 o 0
            'motivo_no_disponible' => $unidad['motivo_no_disponible'],
            'es_propietario'       => $unidad['es_propietario'],//false = es el administrador: el front oculta lo que es solo del propietario
            'faltantes'            => ReglasPublicacion::faltantes((int) $unidad['id']),
        ]]);
    }
}
