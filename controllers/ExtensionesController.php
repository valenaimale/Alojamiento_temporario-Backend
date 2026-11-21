<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Request\Request;
use App\Request\Respuesta;
use App\Validacion\Extension;
use App\Validacion\VencimientoReserva;

//Extender una estadía en curso (US7.1, decisión #70). La regla está en App\Validacion\Extension.
//El contrato de la API está en docs/api/reservas.md ("Extender la estadía").
//La extensión se paga como la seña de su propia reserva (GET /pagos/sena y POST /pagos/confirmar, PagosController).
class ExtensionesController
{
    //GET /reservas/extension?reserva_id=12&noches=2: cuánto cuesta extender, sin guardar nada
    public function cotizar()
    {
        Autorizacion::requiere('huesped');
        header('Cache-Control: no-store');//el precio y las noches libres cambian

        $resultado = Extension::cotizar(
            (int) $_SESSION['usuario']['id'],
            Request::parametroGet('reserva_id'),
            Request::parametroGet('noches')
        );
        if (isset($resultado['error'])) {
            return Respuesta::error($resultado['error'], $resultado['codigo']);
        }
        Respuesta::json($resultado['cotizacion']);
    }

    //POST /reservas/extender: {"reserva_id", "noches"} crea la reserva de extensión pendiente de pago
    public function extender()
    {
        Autorizacion::requiere('huesped');
        $datos = Request::datosJson();

        $resultado = Extension::crear((int) $_SESSION['usuario']['id'], $datos['reserva_id'] ?? null, $datos['noches'] ?? null);
        if (isset($resultado['error'])) {
            return Respuesta::error($resultado['error'], $resultado['codigo']);
        }
        Respuesta::json([
            'ok'      => 'Pagá la extensión antes de las ' . VencimientoReserva::horaArgentina($resultado['vence_en_utc']) . ' para confirmarla',
            'reserva' => $resultado['reserva'],
        ], 201);
    }
}
