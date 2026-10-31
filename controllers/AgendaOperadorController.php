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
    //También incluye estadías abiertas atrasadas y egresos de hoy. El front agrupa los hechos por día.
    public function ver()
    {
        Autorizacion::requiere('operador');

        //Las estadías sin egreso real siguen visibles aunque pase su egreso previsto.
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.estado, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas, h.nombre AS huesped,
                    u.id AS unidad_id, u.nombre AS unidad_nombre, u.direccion, l.nombre AS localidad, l.provincia,
                    e.ingreso_en, e.egreso_en, e.estado_ingreso, e.notas_ingreso, e.estado_egreso, e.notas_egreso
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN localidades l ON l.id = u.localidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             LEFT JOIN estadias e ON e.reserva_id = r.id
             WHERE u.operador_id = ? AND (
                 (r.estado IN ('pendiente', 'confirmada') AND (r.fecha_egreso >= ? OR (e.ingreso_en IS NOT NULL AND e.egreso_en IS NULL)))
                 OR (r.estado = 'finalizada' AND e.egreso_en >= ?)
             )
             ORDER BY r.fecha_ingreso, r.id"
        );
        $hoy = ValidadorFechas::hoy();
        $consulta->execute([$_SESSION['usuario']['id'], $hoy->format('Y-m-d'), $hoy->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);

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
                'estadia'  => EstadiasController::datos($fila),
                'puede_registrar_ingreso' => $fila['estado'] === 'confirmada' && !$fila['ingreso_en'] && $fila['fecha_ingreso'] <= $hoy->format('Y-m-d') && $fila['fecha_egreso'] > $hoy->format('Y-m-d'),
                'puede_registrar_egreso' => $fila['estado'] === 'confirmada' && $fila['ingreso_en'] !== null && $fila['egreso_en'] === null,
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
