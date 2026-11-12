<?php

namespace App\Controllers;

//Los descuentos por estadía larga de una unidad (US2.10, decisión #69). Los ve y los cambia solo el propietario.
//El contrato de la API está en docs/api/condiciones-comerciales.md ("Descuentos por estadía larga").
//FASE 0: las rutas ya existen y responden 501. Lo completa la rama `descuentos`.
class DescuentosController
{
    //GET /unidades/descuentos?id=5: los escalones de la unidad
    public function ver()
    {
        $this->todaviaNo();
    }

    //POST /unidades/descuentos: {"unidad_id", "escalones": [{"noches_minimas", "porcentaje"}]} reemplaza los escalones
    public function guardar()
    {
        $this->todaviaNo();
    }

    //Responde 501 mientras la funcionalidad no está hecha
    private function todaviaNo(): void
    {
        http_response_code(501);
        echo json_encode(['error' => 'Los descuentos por estadía larga todavía no están disponibles']);
    }
}
