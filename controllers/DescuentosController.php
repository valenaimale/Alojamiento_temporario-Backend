<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\Descuentos;

//Los descuentos por estadía larga de una unidad (US2.10, decisión #69). Los ve y los cambia solo el propietario:
//el administrador recibe 403 y una unidad ajena "no existe" (404), igual que la política de cancelación.
//Los cambios valen para las reservas futuras: cada reserva guarda la foto del descuento que se le aplicó.
//La regla de qué escalón se aplica está en App\Validacion\Descuentos.
class DescuentosController
{
    //GET /unidades/descuentos?id=5: los escalones de la unidad, de menos a más noches
    public function ver()
    {
        $unidad = PermisosUnidad::delPropietario($_GET['id'] ?? '');//si no puede, responde y corta acá

        http_response_code(200);
        echo json_encode(['descuentos' => Descuentos::deLaUnidad((int) $unidad['id'])]);
    }

    //POST /unidades/descuentos: {"unidad_id", "descuentos": [{"noches_minimas", "porcentaje"}]}
    //Reemplaza todos los escalones de la unidad; con una lista vacía se borran todos.
    public function guardar()
    {
        $datos = Request::datosJson(['descuentos']);//"descuentos" es una lista: su forma la valida Descuentos::validar
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $escalones = Descuentos::validar($datos['descuentos'] ?? null);
        if (isset($escalones['error'])) {
            return $this->error($escalones['error']);
        }

        //se borran y se vuelven a cargar juntos: nadie ve la unidad a medio cambiar
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM descuentos_estadia WHERE unidad_id = ?')->execute([$unidad['id']]);
            $insertar = $pdo->prepare('INSERT INTO descuentos_estadia (unidad_id, noches_minimas, porcentaje) VALUES (?, ?, ?)');
            foreach ($escalones as $escalon) {
                $insertar->execute([$unidad['id'], $escalon['noches_minimas'], $escalon['porcentaje']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;//lo atrapa el router y responde 500
        }

        http_response_code(200);
        echo json_encode([
            'ok'         => $escalones === [] ? 'Sacamos los descuentos de la unidad' : 'Guardamos los descuentos',
            'descuentos' => $escalones,
        ]);
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
