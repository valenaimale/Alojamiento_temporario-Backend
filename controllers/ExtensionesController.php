<?php

namespace App\Controllers;

//Extender una estadía en curso (US7.1, decisión #70). La regla está en App\Validacion\Extension.
//El contrato de la API está en docs/api/reservas.md ("Extender la estadía").
//FASE 0: las rutas ya existen y responden 501. Lo completa la rama `extension-back`.
class ExtensionesController
{
    //GET /reservas/extension?reserva_id=12&noches=2: cuánto cuesta extender, sin guardar nada
    public function cotizar()
    {
        $this->todaviaNo();
    }

    //POST /reservas/extender: {"reserva_id", "noches"} crea la reserva de extensión pendiente de pago
    public function extender()
    {
        $this->todaviaNo();
    }

    //Responde 501 mientras la funcionalidad no está hecha
    private function todaviaNo(): void
    {
        http_response_code(501);
        echo json_encode(['error' => 'Extender la estadía todavía no está disponible']);
    }
}
