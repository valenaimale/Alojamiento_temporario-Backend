<?php
namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Request\Request;

// Los hechos de la estadía son independientes de los importes y fechas contratadas.
class EstadiasController
{
    public function registrar(): void
    {
        Autorizacion::requiere('operador');
        header('Cache-Control: no-store');
        $d = Request::datosJson();
        $id = is_bool($d['reserva_id'] ?? null) ? false : filter_var($d['reserva_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $tipo = $d['tipo'] ?? '';
        $evento = $d['evento_id'] ?? '';
        $textoFecha = $d['ocurrido_en'] ?? '';
        if ($id === false) { $this->error('No encontramos esa reserva', 404); return; }
        if (!in_array($tipo, ['ingreso', 'egreso'], true) || !is_string($evento) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $evento)) {
            $this->error('El movimiento no es válido'); return;
        }
        $fecha = is_string($textoFecha) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $textoFecha, new \DateTimeZone('UTC')) : false;
        if (!$fecha || $fecha->format('Y-m-d\TH:i:s\Z') !== $textoFecha || $fecha > new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC'))) {
            $this->error('La fecha del movimiento no es válida. Revisá la hora del dispositivo.'); return;
        }
        foreach (['estado_unidad' => 500, 'notas' => 1000] as $campo => $maximo) {
            if (!is_string($d[$campo] ?? '') || mb_strlen($d[$campo] ?? '') > $maximo) {
                $this->error("El campo $campo admite hasta $maximo caracteres"); return;
            }
        }
        $estadoUnidad = trim($d['estado_unidad'] ?? '');
        $notas = trim($d['notas'] ?? '');
        $utc = $fecha->format('Y-m-d H:i:s');
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT r.* FROM reservas r JOIN unidades u ON u.id = r.unidad_id WHERE r.id = ? AND u.operador_id = ? FOR UPDATE');
            $q->execute([$id, $_SESSION['usuario']['id']]);
            $reserva = $q->fetch();
            if (!$reserva) { $pdo->rollBack(); $this->error('No encontramos esa reserva', 404); return; }
            $q = $pdo->prepare('SELECT * FROM estadias WHERE reserva_id = ? FOR UPDATE');
            $q->execute([$id]);
            $estadia = $q->fetch();
            // Reintentar el mismo evento devuelve éxito, incluso después del egreso.
            if ($estadia && $estadia[$tipo . '_evento_id'] !== null) {
                $igual = $estadia[$tipo . '_evento_id'] === $evento
                    && (int) $estadia[$tipo . '_operador_id'] === (int) $_SESSION['usuario']['id']
                    && $estadia[$tipo . '_en'] === $utc
                    && $estadia['estado_' . $tipo] === $estadoUnidad && $estadia['notas_' . $tipo] === $notas;
                $pdo->rollBack();
                if ($igual) $this->ok($tipo, $estadia);
                else $this->error('Ese movimiento ya fue registrado. Actualizá la agenda.', 409);
                return;
            }
            if ($reserva['estado'] !== 'confirmada') {
                $pdo->rollBack(); $this->error('Solo se puede registrar una reserva confirmada', 409); return;
            }
            $dia = $fecha->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d');
            if ($tipo === 'ingreso' && ($dia < $reserva['fecha_ingreso'] || $dia >= $reserva['fecha_egreso'])) {
                $pdo->rollBack(); $this->error('El ingreso debe ocurrir dentro de las fechas de la reserva', 409); return;
            }
            if ($tipo === 'egreso' && (!$estadia || $utc < $estadia['ingreso_en'])) {
                $pdo->rollBack(); $this->error('Registrá primero el ingreso; el egreso no puede ser anterior', 409); return;
            }
            // Un mismo identificador no puede reutilizarse para otra reserva o tipo.
            $q = $pdo->prepare('SELECT reserva_id FROM estadias WHERE ingreso_evento_id = ? OR egreso_evento_id = ?');
            $q->execute([$evento, $evento]);
            if ($q->fetch()) { $pdo->rollBack(); $this->error('El identificador del movimiento ya se utilizó', 409); return; }
            if ($tipo === 'ingreso') {
                $pdo->prepare('INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id, estado_ingreso, notas_ingreso) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)')
                    ->execute([$id, $utc, $_SESSION['usuario']['id'], $evento, $estadoUnidad, $notas]);
            } else {
                $pdo->prepare('UPDATE estadias SET egreso_en = ?, egreso_registrado_en = UTC_TIMESTAMP(), egreso_operador_id = ?, egreso_evento_id = ?, estado_egreso = ?, notas_egreso = ? WHERE reserva_id = ?')
                    ->execute([$utc, $_SESSION['usuario']['id'], $evento, $estadoUnidad, $notas, $id]);
                $pdo->prepare("UPDATE reservas SET estado = 'finalizada' WHERE id = ?")->execute([$id]);
            }
            $q = $pdo->prepare('SELECT * FROM estadias WHERE reserva_id = ?');
            $q->execute([$id]);
            $estadia = $q->fetch();
            $pdo->commit();
            $this->ok($tipo, $estadia);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function datos(?array $fila): ?array
    {
        if (!$fila || empty($fila['ingreso_en'])) return null;
        return [
            'ingreso_en' => str_replace(' ', 'T', $fila['ingreso_en']) . 'Z',
            'egreso_en' => $fila['egreso_en'] ? str_replace(' ', 'T', $fila['egreso_en']) . 'Z' : null,
            'estado_ingreso' => $fila['estado_ingreso'], 'notas_ingreso' => $fila['notas_ingreso'],
            'estado_egreso' => $fila['estado_egreso'], 'notas_egreso' => $fila['notas_egreso'],
        ];
    }

    private function ok(string $tipo, array $estadia): void
    {
        http_response_code(200);
        echo json_encode(['ok' => $tipo === 'ingreso' ? 'Ingreso registrado' : 'Egreso registrado', 'estadia' => self::datos($estadia)]);
    }
    private function error(string $mensaje, int $codigo = 422): void
    {
        http_response_code($codigo); echo json_encode(['error' => $mensaje]);
    }
}
