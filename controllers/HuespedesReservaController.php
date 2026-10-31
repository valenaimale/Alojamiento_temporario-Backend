<?php
namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\ValidadorFechas;

class HuespedesReservaController
{
    public function deLaUnidad(): void
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador(Request::parametroGet('unidad_id'));
        header('Cache-Control: no-store');
        $mes = Request::parametroGet('mes');
        $desde = ValidadorFechas::fecha($mes . '-01');
        if (!$desde) { $this->error('Elegí un mes válido'); return; }
        $hasta = $desde->modify('+1 month')->format('Y-m-d');
        $q = Conexion::obtener()->prepare('SELECT r.id, r.estado, r.fecha_ingreso AS ingreso, r.fecha_egreso AS egreso, r.cantidad_personas AS personas, h.nombre AS titular, (SELECT COUNT(*) FROM huespedes_reserva g WHERE g.reserva_id = r.id) AS cargados FROM reservas r JOIN usuarios h ON h.id = r.huesped_id WHERE r.unidad_id = ? AND r.fecha_ingreso < ? AND r.fecha_egreso > ? ORDER BY r.fecha_ingreso, r.id');
        $q->execute([$unidad['id'], $hasta, $desde->format('Y-m-d')]);
        $reservas = $q->fetchAll();
        foreach ($reservas as &$r) foreach (['id', 'personas', 'cargados'] as $c) $r[$c] = (int) $r[$c];
        echo json_encode(['unidad' => ['id' => (int) $unidad['id'], 'nombre' => $unidad['nombre']], 'reservas' => $reservas]);
    }

    public function ver(): void
    {
        Autorizacion::requiereSesion();
        header('Cache-Control: no-store');
        $reserva = $this->reserva(Request::parametroGet('reserva_id'));
        if (!$reserva) { $this->error('No encontramos esa reserva', 404); return; }
        $filas = $this->filas((int) $reserva['id']);
        echo json_encode([
            'reserva' => [
                'id' => (int) $reserva['id'], 'alojamiento' => $reserva['unidad_nombre'],
                'unidad_id' => (int) $reserva['unidad_id'],
                'ingreso' => $reserva['fecha_ingreso'], 'egreso' => $reserva['fecha_egreso'],
                'personas' => (int) $reserva['cantidad_personas'], 'estado' => $reserva['estado'],
                'es_titular' => (int) $reserva['huesped_id'] === (int) $_SESSION['usuario']['id'],
                'puede_editar' => $this->puedeEditar($reserva),
                'version' => (int) $reserva['version_huespedes'],
                'completo' => count($filas) === (int) $reserva['cantidad_personas'],
                'titular' => ['nombre' => $reserva['titular_nombre'], 'dni' => $reserva['titular_dni']],
            ],
            'huespedes' => $filas,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function guardar(): void
    {
        Autorizacion::requiere('huesped');
        header('Cache-Control: no-store');
        $d = Request::datosJson(['huespedes']);
        $filas = $d['huespedes'] ?? null;
        if (!is_array($filas) || !array_is_list($filas) || count($filas) < 1 || count($filas) > 50) {
            $this->error('Agregá al titular y a quienes se alojan, hasta la cantidad reservada'); return;
        }
        $limpias = [];
        $documentos = [];
        foreach ($filas as $i => $fila) {
            if (!is_array($fila) || array_is_list($fila)) { $this->error('Los datos de un huésped no son válidos'); return; }
            $limpia = [];
            foreach (['nombre' => 120, 'tipo_documento' => 20, 'documento' => 30, 'nacionalidad' => 80, 'fecha_nacimiento' => 10, 'domicilio' => 250] as $campo => $max) {
                if (!is_string($fila[$campo] ?? null) || trim($fila[$campo]) === '' || mb_strlen($fila[$campo]) > $max || preg_match('/[\x00-\x1F\x7F]/u', $fila[$campo])) {
                    $this->error('Revisá ' . $campo . ' del huésped ' . ($i + 1)); return;
                }
                $limpia[$campo] = trim($fila[$campo]);
            }
            if (!in_array($limpia['tipo_documento'], ['DNI', 'Pasaporte', 'Otro'], true)) {
                $this->error('Elegí el tipo de documento del huésped ' . ($i + 1)); return;
            }
            $documento = mb_strtoupper(preg_replace('/[\s.\-]/u', '', $limpia['documento']));
            if (($limpia['tipo_documento'] === 'DNI' && !preg_match('/^[0-9]{7,8}$/D', $documento)) || mb_strlen($documento) < 3) {
                $this->error('Revisá el documento del huésped ' . ($i + 1)); return;
            }
            $clave = $limpia['tipo_documento'] . ':' . $documento;
            if (isset($documentos[$clave])) { $this->error('Hay un documento repetido en la reserva'); return; }
            $documentos[$clave] = true;
            $limpia['documento'] = $documento;
            $nacimiento = ValidadorFechas::fecha($limpia['fecha_nacimiento']);
            if (!$nacimiento || $nacimiento > ValidadorFechas::hoy() || $limpia['fecha_nacimiento'] < '1900-01-01') {
                $this->error('Revisá la fecha de nacimiento del huésped ' . ($i + 1)); return;
            }
            // El primer registro siempre corresponde al titular; no se agregan cuentas a los acompañantes.
            $limpia['es_titular'] = $i === 0;
            $limpias[] = $limpia;
        }
        if (!is_int($d['version'] ?? null) || $d['version'] < 0) { $this->error('Volvé a cargar el libro antes de guardar'); return; }
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $r = $this->reserva($d['reserva_id'] ?? null, true);
            if (!$r || (int) $r['huesped_id'] !== (int) $_SESSION['usuario']['id']) {
                $pdo->rollBack(); $this->error('No encontramos esa reserva', 404); return;
            }
            if (!$this->puedeEditar($r)) { $pdo->rollBack(); $this->error('Los huéspedes se pueden modificar antes del día de ingreso, en reservas pendientes o confirmadas', 409); return; }
            if (count($limpias) > (int) $r['cantidad_personas']) { $pdo->rollBack(); $this->error('La cantidad de huéspedes supera las personas de la reserva'); return; }
            if ((int) $r['version_huespedes'] !== $d['version']) { $pdo->rollBack(); $this->error('El libro cambió en otra pestaña. Conservamos lo que escribiste; recargá para revisar la versión actual.', 409); return; }
            $pdo->prepare('DELETE FROM huespedes_reserva WHERE reserva_id = ?')->execute([$r['id']]);
            $q = $pdo->prepare('INSERT INTO huespedes_reserva (reserva_id, orden, es_titular, nombre, tipo_documento, documento, nacionalidad, fecha_nacimiento, domicilio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($limpias as $i => $fila) {
                $q->execute([$r['id'], $i, (int) $fila['es_titular'], $fila['nombre'], $fila['tipo_documento'], $fila['documento'], $fila['nacionalidad'], $fila['fecha_nacimiento'], $fila['domicilio']]);
            }
            $pdo->prepare('UPDATE reservas SET version_huespedes = version_huespedes + 1 WHERE id = ?')->execute([$r['id']]);
            $pdo->commit();
            echo json_encode(['ok' => 'Guardamos los huéspedes', 'version' => $d['version'] + 1, 'completo' => count($limpias) === (int) $r['cantidad_personas']]);
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public function exportar(): void
    {
        Autorizacion::requiereSesion();
        header('Cache-Control: no-store');
        $r = $this->reserva(Request::parametroGet('reserva_id'));
        if (!$r) { $this->error('No encontramos esa reserva', 404); return; }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="huespedes-reserva-' . (int) $r['id'] . '.csv"');
        $salida = fopen('php://output', 'w');
        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, ['Reserva', 'Alojamiento', 'Ingreso', 'Egreso', 'Titular', 'Nombre', 'Tipo de documento', 'Documento', 'Nacionalidad', 'Nacimiento', 'Domicilio'], ';', '"', '');
        foreach ($this->filas((int) $r['id']) as $fila) {
            $valores = [$r['id'], $r['unidad_nombre'], $r['fecha_ingreso'], $r['fecha_egreso'], $fila['es_titular'] ? 'Sí' : 'No', $fila['nombre'], $fila['tipo_documento'], $fila['documento'], $fila['nacionalidad'], $fila['fecha_nacimiento'], $fila['domicilio']];
            // Evita que una planilla ejecute nombres/domicilios como fórmulas.
            $valores = array_map(fn($v) => preg_match('/^\s*[=+@\-\t\r]/u', (string) $v) ? "'" . $v : $v, $valores);
            fputcsv($salida, $valores, ';', '"', '');
        }
        fclose($salida);
    }

    private function reserva(mixed $id, bool $bloquear = false): array|false
    {
        $numero = is_bool($id) ? false : filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($numero === false) return false;
        $usuario = $_SESSION['usuario'];
        if (!in_array($usuario['rol'], ['huesped', 'propietario', 'administrador'], true)) return false;
        $q = Conexion::obtener()->prepare('SELECT r.*, u.nombre AS unidad_nombre, u.propietario_id, a.administrador_id, h.nombre AS titular_nombre, h.dni AS titular_dni, e.ingreso_en FROM reservas r JOIN unidades u ON u.id = r.unidad_id JOIN usuarios h ON h.id = r.huesped_id LEFT JOIN administraciones a ON a.unidad_id = u.id LEFT JOIN estadias e ON e.reserva_id = r.id WHERE r.id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $q->execute([$numero]); $r = $q->fetch();
        if (!$r) return false;
        $titular = (int) $r['huesped_id'] === (int) $usuario['id'];
        $propietario = $usuario['rol'] === 'propietario' && (int) $r['propietario_id'] === (int) $usuario['id'];
        $admin = $usuario['rol'] === 'administrador' && (int) $r['administrador_id'] === (int) $usuario['id'];
        return $titular || $propietario || $admin ? $r : false;
    }
    private function puedeEditar(array $r): bool
    {
        return (int) $r['huesped_id'] === (int) $_SESSION['usuario']['id']
            && in_array($r['estado'], ['pendiente', 'confirmada'], true)
            && $r['fecha_ingreso'] > ValidadorFechas::hoy()->format('Y-m-d') && $r['ingreso_en'] === null;
    }
    private function filas(int $id): array
    {
        $q = Conexion::obtener()->prepare('SELECT es_titular, nombre, tipo_documento, documento, nacionalidad, fecha_nacimiento, domicilio FROM huespedes_reserva WHERE reserva_id = ? ORDER BY orden');
        $q->execute([$id]); $filas = $q->fetchAll();
        foreach ($filas as &$fila) $fila['es_titular'] = (bool) $fila['es_titular'];
        return $filas;
    }
    private function error(string $mensaje, int $codigo = 422): void
    {
        http_response_code($codigo); echo json_encode(['error' => $mensaje]);
    }
}
