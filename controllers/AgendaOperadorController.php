<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Validacion\ValidadorFechas;
use App\Validacion\PlazoIngreso;
use App\Validacion\VencimientoReserva;

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
            "SELECT r.id, r.estado, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas, r.monto_faltante, h.nombre AS huesped,
                    u.id AS unidad_id, u.nombre AS unidad_nombre, u.direccion, u.hora_check_in, l.nombre AS localidad, l.provincia,
                    e.ingreso_en, e.egreso_en, e.estado_ingreso, e.notas_ingreso, e.estado_egreso, e.notas_egreso
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN localidades l ON l.id = u.localidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             LEFT JOIN estadias e ON e.reserva_id = r.id
             WHERE u.operador_id = ? AND r.extiende_reserva_id IS NULL AND (
                 ((r.estado = 'confirmada' OR " . VencimientoReserva::pendienteVigente('r') . ")
                  AND (r.fecha_egreso >= ? OR (e.ingreso_en IS NOT NULL AND e.egreso_en IS NULL)))
                 OR (r.estado = 'finalizada' AND e.egreso_en >= ?)
             )
             ORDER BY r.fecha_ingreso, r.id"
        );
        $hoy = ValidadorFechas::hoy();
        $consulta->execute([$_SESSION['usuario']['id'], $hoy->format('Y-m-d'), $hoy->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);

        $filas = $consulta->fetchAll();
        $extensiones = [];
        if ($filas) {
            $marcadores = implode(', ', array_fill(0, count($filas), '?'));
            $consultaExtensiones = Conexion::obtener()->prepare(
                "SELECT id, extiende_reserva_id, fecha_ingreso, fecha_egreso, estado FROM reservas
                 WHERE extiende_reserva_id IN ($marcadores) AND estado IN ('confirmada', 'finalizada')
                 ORDER BY fecha_ingreso, id"
            );
            $consultaExtensiones->execute(array_column($filas, 'id'));
            foreach ($consultaExtensiones->fetchAll() as $extension) {
                $extensiones[(int) $extension['extiende_reserva_id']][] = [
                    'id' => (int) $extension['id'],
                    'ingreso' => $extension['fecha_ingreso'],
                    'egreso' => $extension['fecha_egreso'],
                    'noches' => ValidadorFechas::noches(ValidadorFechas::fecha($extension['fecha_ingreso']), ValidadorFechas::fecha($extension['fecha_egreso'])),
                    'estado' => $extension['estado'],
                ];
            }
        }
        $ahora = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $reservas = [];
        foreach ($filas as $fila) {
            $deLaReserva = $extensiones[(int) $fila['id']] ?? [];
            $egreso = $fila['fecha_egreso'];
            foreach ($deLaReserva as $extension) $egreso = max($egreso, $extension['egreso']);
            // Contrato pendiente de fase 0: limite(fechaIngreso, horaCheckIn): DateTimeImmutable.
            $limite = !$fila['ingreso_en'] ? PlazoIngreso::limite($fila['fecha_ingreso'], $fila['hora_check_in']) : null;
            $saldoPagado = $fila['monto_faltante'] !== null && bccomp($fila['monto_faltante'], '0', 2) <= 0;
            $reservas[] = [
                'id'       => (int) $fila['id'],
                'estado'   => $fila['estado'],
                'ingreso'  => $fila['fecha_ingreso'],
                'egreso'   => $egreso,
                'egreso_original' => $fila['fecha_egreso'],
                'extensiones' => $deLaReserva,
                'limite_ingreso' => $limite?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'noches'   => ValidadorFechas::noches(ValidadorFechas::fecha($fila['fecha_ingreso']), ValidadorFechas::fecha($egreso)),
                'personas' => (int) $fila['cantidad_personas'],
                'huesped'  => $fila['huesped'],
                'estadia'  => EstadiasController::datos($fila),
                'saldo_pagado' => $saldoPagado,
                'puede_registrar_ingreso' => $fila['estado'] === 'confirmada' && !$fila['ingreso_en'] && $saldoPagado && $ahora < $limite && $fila['fecha_ingreso'] <= $hoy->format('Y-m-d'),
                'puede_registrar_egreso' => $fila['estado'] === 'confirmada' && $fila['ingreso_en'] !== null && $fila['egreso_en'] === null,
                'unidad'   => [
                    'id'        => (int) $fila['unidad_id'],
                    'nombre'    => $fila['unidad_nombre'],
                    'hora_check_in' => substr($fila['hora_check_in'], 0, 5),
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
