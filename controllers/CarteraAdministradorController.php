<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;

//La cartera del administrador (US2.5): las unidades que administra, con el estado de cada una y su comisión.
//De cada propietario muestra solo el nombre (el resto lo tienen por el acuerdo que hicieron por fuera).
class CarteraAdministradorController
{
    //GET /administrador/unidades: las unidades del administrador logueado, ordenadas por nombre
    public function listar()
    {
        Autorizacion::requiere('administrador');//sin sesión 401, otro rol 403
        $administradorId = $_SESSION['usuario']['id'];//el id sale siempre de la sesión

        $consulta = Conexion::obtener()->prepare(
            'SELECT u.id, u.nombre, l.nombre AS localidad, l.provincia, p.nombre AS propietario,
                    u.estado_publicacion, u.disponible, a.porcentaje_comision,
                    (SELECT f.url FROM fotos_unidad f WHERE f.unidad_id = u.id AND f.orden = 0) AS foto_principal
             FROM administraciones a
             JOIN unidades u ON u.id = a.unidad_id
             JOIN localidades l ON l.id = u.localidad_id
             JOIN usuarios p ON p.id = u.propietario_id
             WHERE a.administrador_id = ?
             ORDER BY u.nombre'
        );
        $consulta->execute([$administradorId]);
        $unidades = $consulta->fetchAll();

        foreach ($unidades as &$unidad) {
            $unidad['id'] = (int) $unidad['id'];//MySQL los devuelve como texto
            $unidad['disponible'] = (bool) $unidad['disponible'];
        }

        http_response_code(200);
        echo json_encode(['unidades' => $unidades]);
    }
}
