<?php
namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\PlazoIngreso;

// Los hechos de la estadía son independientes de los importes y fechas contratadas.
class EstadiasController
{
    public function registrar(): void
    {
        Autorizacion::requiereSesion();
        $rol = $_SESSION['usuario']['rol'];
        if (!in_array($rol, ['operador', 'propietario', 'administrador'], true)) {
            $this->error('No tenés permiso para hacer esto', 403); return;
        }
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
            $permiso = match ($rol) {
                'operador' => 'u.operador_id = ?',
                'propietario' => 'u.propietario_id = ?',
                'administrador' => 'EXISTS (SELECT 1 FROM administraciones a WHERE a.unidad_id = u.id AND a.administrador_id = ?)',
            };
            $q = $pdo->prepare("SELECT r.*, u.hora_check_in FROM reservas r JOIN unidades u ON u.id = r.unidad_id WHERE r.id = ? AND $permiso FOR UPDATE");
            $q->execute([$id, $_SESSION['usuario']['id']]);
            $reserva = $q->fetch();
            if (!$reserva) { $pdo->rollBack(); $this->error('No encontramos esa reserva', 404); return; }
            if ($reserva['extiende_reserva_id'] !== null) {
                $pdo->rollBack(); $this->error('Registrá el movimiento en la reserva original', 409); return;
            }
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
            // Decisión #25: el saldo se verifica bajo el mismo bloqueo que usa el pago.
            if ($tipo === 'ingreso' && ($reserva['monto_faltante'] === null || bccomp($reserva['monto_faltante'], '0', 2) > 0)) {
                $pdo->rollBack();
                $sinDato = $reserva['monto_faltante'] === null;
                $this->error($sinDato ? 'No se pudo verificar el saldo de esta reserva. Consultá al administrador.' : 'Falta pagar el saldo', 409,
                    ['codigo' => $sinDato ? 'SALDO_NO_INFORMADO' : 'SALDO_PENDIENTE']);
                return;
            }
            if ($tipo === 'ingreso') {
                // Contrato pendiente de fase 0: limite(fechaIngreso, horaCheckIn): DateTimeImmutable.
                $limite = PlazoIngreso::limite($reserva['fecha_ingreso'], $reserva['hora_check_in']);
                // La fecha enviada por el dispositivo no permite eludir un plazo ya vencido.
                if ($fecha >= $limite || new \DateTimeImmutable('now', new \DateTimeZone('UTC')) >= $limite) {
                    $pdo->rollBack(); $this->error('Venció el plazo de 24 horas para registrar el ingreso', 409,
                        ['codigo' => 'PLAZO_INGRESO_VENCIDO']); return;
                }
            }
            $dia = $fecha->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d');
            if ($tipo === 'ingreso' && $dia < $reserva['fecha_ingreso']) {
                $pdo->rollBack(); $this->error('El ingreso no puede ocurrir antes de la fecha de la reserva', 409); return;
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
                // La estadía y sus extensiones comparten un único movimiento de egreso.
                // El bloqueo del original también debe usarse al crear o confirmar una extensión.
                $q = $pdo->prepare("SELECT id FROM reservas WHERE extiende_reserva_id = ? AND estado = 'confirmada' ORDER BY id FOR UPDATE");
                $q->execute([$id]);
                $extensiones = $q->fetchAll(\PDO::FETCH_COLUMN);
                $pdo->prepare("UPDATE reservas SET estado = 'finalizada' WHERE id = ?")->execute([$id]);
                foreach ($extensiones as $extensionId) {
                    $pdo->prepare("UPDATE reservas SET estado = 'finalizada' WHERE id = ? AND estado = 'confirmada'")->execute([$extensionId]);
                }
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
    private function error(string $mensaje, int $codigo = 422, array $datos = []): void
    {
        http_response_code($codigo); echo json_encode(['error' => $mensaje] + $datos);
    }
}
