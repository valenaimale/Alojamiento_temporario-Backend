<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\ValidadorFechas;

//Fechas bloqueadas de una unidad (US3.3): noches que no se pueden reservar en la plataforma, porque se reservaron
//por otro medio (Airbnb, Booking), por mantenimiento, por daños, porque el propietario no quiere recibir reservas, etc.
//Los pueden manejar el propietario y el administrador asignado. El motivo es solo para ellos (para recordar por qué
//bloquearon): el huésped nunca lo ve, para él la noche está "ocupada".
//fecha_desde y fecha_hasta son noches, las dos incluidas. Las noches pasadas no se bloquean ni se liberan.
class BloqueosController
{
    private const MOTIVOS = ['reserva_externa', 'mantenimiento', 'danio', 'uso_personal', 'otro'];
    private const MAXIMO_NOCHES = 366;
    private const MAXIMO_DETALLE = 255;

    //GET /unidades/bloqueos?id=5: los bloqueos que todavía no terminaron, ordenados por fecha, con quién los cargó
    public function listar()
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador($_GET['id'] ?? '');//si no puede, responde y corta acá

        $consulta = Conexion::obtener()->prepare(
            'SELECT b.id, b.fecha_desde, b.fecha_hasta, b.motivo, b.detalle, u.nombre AS creado_por
             FROM bloqueos_calendario b
             JOIN usuarios u ON u.id = b.creado_por
             WHERE b.unidad_id = ? AND b.fecha_hasta >= ?
             ORDER BY b.fecha_desde'
        );
        $consulta->execute([$unidad['id'], ValidadorFechas::hoy()->format('Y-m-d')]);
        $bloqueos = $consulta->fetchAll();

        foreach ($bloqueos as &$bloqueo) {
            $bloqueo['id'] = (int) $bloqueo['id'];//MySQL lo devuelve como texto
        }

        http_response_code(200);
        echo json_encode(['bloqueos' => $bloqueos]);
    }

    //POST /unidades/bloqueos/crear: {"unidad_id", "fecha_desde", "fecha_hasta", "motivo", "detalle"}
    public function crear()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietarioOAdministrador($datos['unidad_id'] ?? '');

        $bloqueo = $this->validar($datos);
        if (isset($bloqueo['error'])) {
            return $this->error($bloqueo['error']);
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //traba la fila de la unidad hasta el commit: dos bloqueos a la vez no pueden pisarse
            $pdo->prepare('SELECT id FROM unidades WHERE id = ? FOR UPDATE')->execute([$unidad['id']]);

            $consulta = $pdo->prepare(
                'SELECT fecha_desde, fecha_hasta FROM bloqueos_calendario
                 WHERE unidad_id = ? AND fecha_desde <= ? AND fecha_hasta >= ?
                 ORDER BY fecha_desde
                 LIMIT 1'
            );
            $consulta->execute([$unidad['id'], $bloqueo['fecha_hasta'], $bloqueo['fecha_desde']]);
            $otro = $consulta->fetch();
            if ($otro !== false) {
                $pdo->rollBack();
                return $this->error(sprintf(
                    'Se superpone con otro bloqueo, del %s al %s',
                    ValidadorFechas::fecha($otro['fecha_desde'])->format('d/m/Y'),
                    ValidadorFechas::fecha($otro['fecha_hasta'])->format('d/m/Y')
                ), 409);
            }

            //Tampoco puede pisar una reserva (pendiente o confirmada): bloquear esas noches sería una cancelación
            //del propietario, que tiene su propio trato. La noche del egreso no es de la reserva.
            $consulta = $pdo->prepare(
                "SELECT fecha_ingreso, fecha_egreso FROM reservas
                 WHERE unidad_id = ? AND estado IN ('pendiente', 'confirmada') AND fecha_ingreso <= ? AND fecha_egreso > ?
                 ORDER BY fecha_ingreso
                 LIMIT 1"
            );
            $consulta->execute([$unidad['id'], $bloqueo['fecha_hasta'], $bloqueo['fecha_desde']]);
            $reserva = $consulta->fetch();
            if ($reserva !== false) {
                $pdo->rollBack();
                return $this->error(sprintf(
                    'Esas fechas tienen una reserva, con ingreso el %s y egreso el %s: no se pueden bloquear',
                    ValidadorFechas::fecha($reserva['fecha_ingreso'])->format('d/m/Y'),
                    ValidadorFechas::fecha($reserva['fecha_egreso'])->format('d/m/Y')
                ), 409);
            }

            $consulta = $pdo->prepare(
                'INSERT INTO bloqueos_calendario (unidad_id, fecha_desde, fecha_hasta, motivo, detalle, creado_por)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $consulta->execute([
                $unidad['id'], $bloqueo['fecha_desde'], $bloqueo['fecha_hasta'], $bloqueo['motivo'], $bloqueo['detalle'],
                $_SESSION['usuario']['id'],//quién lo bloqueó: el propietario o el administrador
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;//lo atrapa el router y responde 500
        }

        http_response_code(201);
        echo json_encode(['ok' => 'Bloqueaste esas fechas', 'id' => $id]);
    }

    //POST /unidades/bloqueos/liberar: {"unidad_id", "bloqueo_id"}: esas noches se vuelven a poder reservar.
    //Las noches pasadas no se liberan: un bloqueo en curso se recorta hasta ayer (queda como historial),
    //uno que todavía no empezó se borra.
    public function liberar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietarioOAdministrador($datos['unidad_id'] ?? '');

        $bloqueoId = filter_var($datos['bloqueo_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($bloqueoId === false) {
            return $this->error('Bloqueo inválido');
        }

        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare('SELECT fecha_desde, fecha_hasta FROM bloqueos_calendario WHERE id = ? AND unidad_id = ?');
        $consulta->execute([$bloqueoId, $unidad['id']]);
        $bloqueo = $consulta->fetch();
        if ($bloqueo === false) {//no existe, o es de otra unidad
            return $this->error('No encontramos ese bloqueo', 404);
        }

        $hoy = ValidadorFechas::hoy();
        if ($bloqueo['fecha_hasta'] < $hoy->format('Y-m-d')) {//AAAA-MM-DD se compara como texto
            return $this->error('Ese bloqueo ya terminó: las noches pasadas no se liberan', 409);
        }

        if ($bloqueo['fecha_desde'] < $hoy->format('Y-m-d')) {
            //en curso: las noches pasadas quedan bloqueadas, se liberan de hoy en adelante
            $pdo->prepare('UPDATE bloqueos_calendario SET fecha_hasta = ? WHERE id = ?')
                ->execute([$hoy->modify('-1 day')->format('Y-m-d'), $bloqueoId]);
            $mensaje = 'Liberamos esas fechas de hoy en adelante';
        } else {
            $pdo->prepare('DELETE FROM bloqueos_calendario WHERE id = ?')->execute([$bloqueoId]);
            $mensaje = 'Liberaste esas fechas';
        }

        http_response_code(200);
        echo json_encode(['ok' => $mensaje]);
    }

    //Valida los datos de un bloqueo. Devuelve los datos listos para guardar, o ['error' => mensaje].
    private function validar(array $datos): array
    {
        $desde = ValidadorFechas::fecha((string) ($datos['fecha_desde'] ?? ''));
        $hasta = ValidadorFechas::fecha((string) ($datos['fecha_hasta'] ?? ''));
        if ($desde === null || $hasta === null) {
            return ['error' => 'Las fechas tienen que tener el formato AAAA-MM-DD'];
        }
        if ($desde > $hasta) {
            return ['error' => 'La primera noche no puede ser posterior a la última'];
        }
        if ($desde < ValidadorFechas::hoy()) {
            return ['error' => 'Solo podés bloquear noches de hoy en adelante'];
        }
        if (ValidadorFechas::noches($desde, $hasta) + 1 > self::MAXIMO_NOCHES) {//+1: las dos fechas son noches incluidas
            return ['error' => 'Podés bloquear hasta ' . self::MAXIMO_NOCHES . ' noches de una vez'];
        }

        $motivo = (string) ($datos['motivo'] ?? '');
        if (!in_array($motivo, self::MOTIVOS, true)) {
            return ['error' => 'Elegí el motivo del bloqueo'];
        }

        $detalle = trim((string) ($datos['detalle'] ?? ''));
        if (mb_strlen($detalle) > self::MAXIMO_DETALLE) {
            return ['error' => 'El detalle puede tener hasta ' . self::MAXIMO_DETALLE . ' caracteres'];
        }
        if ($motivo === 'otro' && $detalle === '') {
            return ['error' => 'Contanos el motivo del bloqueo'];
        }

        return [
            'fecha_desde' => $desde->format('Y-m-d'),
            'fecha_hasta' => $hasta->format('Y-m-d'),
            'motivo'      => $motivo,
            'detalle'     => $detalle === '' ? null : $detalle,
        ];
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
