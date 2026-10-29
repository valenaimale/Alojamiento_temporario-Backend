<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Validacion\ValidadorFechas;

//La agenda del operador de estadías (RF-10): los ingresos y egresos de las unidades que tiene asignadas.
//El operador recibe a los huéspedes: ve la dirección de la unidad y el nombre del huésped, pero nunca los montos (RN-19).
class AgendaOperadorController
{
    //GET /operador/agenda: las reservas en curso y próximas (pendientes y confirmadas) de sus unidades,
    //de la más cercana a la más lejana. El front arma con ellas los ingresos y egresos de cada día.
    public function ver()
    {
        Autorizacion::requiere('operador');

        //"en curso y próximas": el egreso es de hoy en adelante (hoy en Argentina, calculado en PHP)
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.estado, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas, h.nombre AS huesped,
                    u.id AS unidad_id, u.nombre AS unidad_nombre, u.direccion, l.nombre AS localidad, l.provincia
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN localidades l ON l.id = u.localidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             WHERE u.operador_id = ? AND r.estado IN ('pendiente', 'confirmada') AND r.fecha_egreso >= ?
             ORDER BY r.fecha_ingreso, r.id"
        );
        $consulta->execute([$_SESSION['usuario']['id'], ValidadorFechas::hoy()->format('Y-m-d')]);

        $reservas = [];
        foreach ($consulta->fetchAll() as $fila) {
            $reservas[] = [
                'id'       => (int) $fila['id'],
                'estado'   => $fila['estado'],
                'ingreso'  => $fila['fecha_ingreso'],
                'egreso'   => $fila['fecha_egreso'],
                'noches'   => ValidadorFechas::noches(ValidadorFechas::fecha($fila['fecha_ingreso']), ValidadorFechas::fecha($fila['fecha_egreso'])),
                'personas' => (int) $fila['cantidad_personas'],
                'huesped'  => $fila['huesped'],
                'unidad'   => [
                    'id'        => (int) $fila['unidad_id'],
                    'nombre'    => $fila['unidad_nombre'],
                    'direccion' => $fila['direccion'],//el operador va a la unidad: necesita la dirección
                    'localidad' => $fila['localidad'],
                    'provincia' => $fila['provincia'],
                ],
            ];
        }

        http_response_code(200);
        echo json_encode(['reservas' => $reservas]);
    }
}
