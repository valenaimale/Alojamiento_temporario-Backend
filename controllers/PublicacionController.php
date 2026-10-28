<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\ReglasPublicacion;

//Publicar y pausar una unidad (US3.1). Solo el propietario.
//Publicada: aparece en la búsqueda. Pausada: sale de la búsqueda, pero conserva sus reservas.
//Borrador: nunca se publicó (así nace). Una unidad pausada se puede volver a publicar.
class PublicacionController
{
    //POST /unidades/publicar: {"unidad_id"}. Solo si cumple las reglas de publicación (ReglasPublicacion)
    //y no está fuera de servicio por un daño. Si ya está publicada, no cambia nada.
    public function publicar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');//si no puede, responde y corta acá

        if ($unidad['estado_publicacion'] === 'publicada') {
            return $this->responder('La unidad ya está publicada', 'publicada');
        }
        if (!$unidad['disponible']) {//fuera de servicio por un daño (Épica 8)
            return $this->error('No se puede publicar: la unidad está fuera de servicio', 409);
        }
        $faltantes = ReglasPublicacion::faltantes((int) $unidad['id']);
        if ($faltantes !== []) {
            http_response_code(422);
            echo json_encode(['error' => 'Para publicarla te falta completar algunas cosas', 'faltantes' => $faltantes]);
            return;
        }

        $this->cambiarEstado((int) $unidad['id'], 'publicada');
        $this->responder('Publicaste la unidad: ya aparece en la búsqueda', 'publicada');
    }

    //POST /unidades/pausar: {"unidad_id"}. Solo una unidad publicada (o ya pausada: no cambia nada).
    public function pausar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        if ($unidad['estado_publicacion'] === 'pausada') {
            return $this->responder('La unidad ya está pausada', 'pausada');
        }
        if ($unidad['estado_publicacion'] !== 'publicada') {//borrador: no tiene sentido pausar algo que nunca se publicó
            return $this->error('La unidad no está publicada', 409);
        }

        $this->cambiarEstado((int) $unidad['id'], 'pausada');
        $this->responder('Pausaste la unidad: ya no aparece en la búsqueda. Sus reservas siguen en pie', 'pausada');
    }

    //Guarda el estado nuevo de la unidad
    private function cambiarEstado(int $unidadId, string $estado): void
    {
        Conexion::obtener()->prepare('UPDATE unidades SET estado_publicacion = ? WHERE id = ?')->execute([$estado, $unidadId]);
    }

    //Responde 200 con el mensaje y el estado en que quedó la unidad
    private function responder(string $mensaje, string $estado)
    {
        http_response_code(200);
        echo json_encode(['ok' => $mensaje, 'estado_publicacion' => $estado]);
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
