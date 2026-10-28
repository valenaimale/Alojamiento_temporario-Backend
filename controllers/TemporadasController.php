<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\ValidadorFechas;

//Las temporadas de una unidad (US2.7, US2.9): alta, media o baja, con sus fechas y su mínimo de noches.
//No tienen precio: el precio se carga por noche (PreciosController). Son opcionales: las noches sin
//temporada tienen mínimo 1. Dos temporadas de la misma unidad no se pueden superponer.
//Todo es solo del propietario de la unidad.
class TemporadasController
{
    private const NOMBRES = ['alta', 'media', 'baja'];
    private const MAXIMO_NOCHES = 366;          //una temporada dura como mucho un año
    private const MAXIMO_MINIMO_NOCHES = 60;

    //GET /unidades/temporadas?id=5: las temporadas que todavía no terminaron, ordenadas por fecha.
    //Las que ya terminaron no se muestran: no se pueden editar y ya no afectan a ninguna estadía nueva.
    public function listar()
    {
        $unidad = PermisosUnidad::delPropietario($_GET['id'] ?? '');//si no puede, responde y corta acá

        $consulta = Conexion::obtener()->prepare(
            'SELECT id, nombre, fecha_inicio, fecha_fin, minimo_noches
             FROM temporadas
             WHERE unidad_id = ? AND fecha_fin >= ?
             ORDER BY fecha_inicio'
        );
        $consulta->execute([$unidad['id'], ValidadorFechas::hoy()->format('Y-m-d')]);
        $temporadas = $consulta->fetchAll();

        foreach ($temporadas as &$temporada) {
            $temporada['id'] = (int) $temporada['id'];//MySQL los devuelve como texto
            $temporada['minimo_noches'] = (int) $temporada['minimo_noches'];
        }

        http_response_code(200);
        echo json_encode(['temporadas' => $temporadas]);
    }

    //POST /unidades/temporadas/crear: {"unidad_id", "nombre", "fecha_inicio", "fecha_fin", "minimo_noches"}
    public function crear()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $temporada = $this->validar($datos);
        if (isset($temporada['error'])) {
            return $this->error($temporada['error']);
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->bloquearUnidad((int) $unidad['id']);
            $superpuesta = $this->superpuesta((int) $unidad['id'], $temporada);
            if ($superpuesta !== null) {
                $pdo->rollBack();
                return $this->error($superpuesta, 409);
            }

            $consulta = $pdo->prepare(
                'INSERT INTO temporadas (unidad_id, nombre, fecha_inicio, fecha_fin, minimo_noches) VALUES (?, ?, ?, ?, ?)'
            );
            $consulta->execute([
                $unidad['id'], $temporada['nombre'], $temporada['fecha_inicio'], $temporada['fecha_fin'], $temporada['minimo_noches'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;//lo atrapa el router y responde 500
        }

        http_response_code(201);
        echo json_encode(['ok' => 'Creaste la temporada', 'id' => $id]);
    }

    //POST /unidades/temporadas/editar: {"unidad_id", "temporada_id", "nombre", "fecha_inicio", "fecha_fin", "minimo_noches"}
    //Solo mientras no haya terminado (una temporada pasada no se cambia).
    public function editar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $temporadaId = $this->idTemporada($datos);
        if ($temporadaId === null) {
            return $this->error('Temporada inválida');
        }
        $temporada = $this->validar($datos);
        if (isset($temporada['error'])) {
            return $this->error($temporada['error']);
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->bloquearUnidad((int) $unidad['id']);

            $consulta = $pdo->prepare('SELECT fecha_fin FROM temporadas WHERE id = ? AND unidad_id = ?');
            $consulta->execute([$temporadaId, $unidad['id']]);
            $finActual = $consulta->fetchColumn();//false si no existe o es de otra unidad
            if ($finActual === false) {
                $pdo->rollBack();
                return $this->error('No encontramos esa temporada', 404);
            }
            if ($finActual < ValidadorFechas::hoy()->format('Y-m-d')) {//las fechas AAAA-MM-DD se pueden comparar como texto
                $pdo->rollBack();
                return $this->error('Esa temporada ya terminó: no se puede editar', 409);
            }

            $superpuesta = $this->superpuesta((int) $unidad['id'], $temporada, $temporadaId);
            if ($superpuesta !== null) {
                $pdo->rollBack();
                return $this->error($superpuesta, 409);
            }

            $consulta = $pdo->prepare(
                'UPDATE temporadas SET nombre = ?, fecha_inicio = ?, fecha_fin = ?, minimo_noches = ? WHERE id = ?'
            );
            $consulta->execute([
                $temporada['nombre'], $temporada['fecha_inicio'], $temporada['fecha_fin'], $temporada['minimo_noches'], $temporadaId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        http_response_code(200);
        echo json_encode(['ok' => 'Guardamos los cambios de la temporada']);
    }

    //POST /unidades/temporadas/borrar: {"unidad_id", "temporada_id"}.
    //Borrado físico: nada apunta a una temporada (cada reserva va a guardar su propia copia del mínimo de noches).
    public function borrar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $temporadaId = $this->idTemporada($datos);
        if ($temporadaId === null) {
            return $this->error('Temporada inválida');
        }

        $consulta = Conexion::obtener()->prepare('DELETE FROM temporadas WHERE id = ? AND unidad_id = ?');
        $consulta->execute([$temporadaId, $unidad['id']]);
        if ($consulta->rowCount() === 0) {//no existe, o es de otra unidad
            return $this->error('No encontramos esa temporada', 404);
        }

        http_response_code(200);
        echo json_encode(['ok' => 'Borraste la temporada']);
    }

    //Valida los datos de una temporada (crear y editar). Devuelve los datos listos para guardar, o ['error' => mensaje].
    private function validar(array $datos): array
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if (!in_array($nombre, self::NOMBRES, true)) {
            return ['error' => 'Elegí si es temporada alta, media o baja'];
        }

        $inicio = ValidadorFechas::fecha((string) ($datos['fecha_inicio'] ?? ''));
        $fin = ValidadorFechas::fecha((string) ($datos['fecha_fin'] ?? ''));
        if ($inicio === null || $fin === null) {
            return ['error' => 'Las fechas tienen que tener el formato AAAA-MM-DD'];
        }
        if ($inicio > $fin) {
            return ['error' => 'La fecha de inicio no puede ser posterior a la de fin'];
        }
        if ($fin < ValidadorFechas::hoy()) {
            return ['error' => 'La temporada tiene que terminar hoy o más adelante'];
        }
        if (ValidadorFechas::noches($inicio, $fin) + 1 > self::MAXIMO_NOCHES) {//+1: las dos fechas son noches incluidas
            return ['error' => 'Una temporada puede durar hasta ' . self::MAXIMO_NOCHES . ' noches'];
        }

        $minimo = filter_var($datos['minimo_noches'] ?? '', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAXIMO_MINIMO_NOCHES],
        ]);
        if ($minimo === false) {
            return ['error' => 'El mínimo de noches tiene que ser un número de 1 a ' . self::MAXIMO_MINIMO_NOCHES];
        }

        return [
            'nombre'        => $nombre,
            'fecha_inicio'  => $inicio->format('Y-m-d'),
            'fecha_fin'     => $fin->format('Y-m-d'),
            'minimo_noches' => $minimo,
        ];
    }

    //El temporada_id del cuerpo como entero positivo, o null si no lo es
    private function idTemporada(array $datos): ?int
    {
        $id = filter_var($datos['temporada_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    //Traba la fila de la unidad hasta el commit: si llegan dos pedidos juntos para la misma unidad, el segundo
    //espera al primero. Sin esto, los dos podrían ver que "no hay superposición" y guardar dos temporadas que se pisan.
    private function bloquearUnidad(int $unidadId): void
    {
        Conexion::obtener()->prepare('SELECT id FROM unidades WHERE id = ? FOR UPDATE')->execute([$unidadId]);
    }

    //Si la temporada se superpone con otra de la unidad, devuelve el mensaje que lo dice; si no, null.
    //Dos rangos de noches (con las dos fechas incluidas) se superponen si cada uno empieza antes de que termine el otro.
    //$excluirId: al editar, la temporada no se compara consigo misma.
    private function superpuesta(int $unidadId, array $temporada, int $excluirId = 0): ?string
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT nombre, fecha_inicio, fecha_fin
             FROM temporadas
             WHERE unidad_id = ? AND id <> ? AND fecha_inicio <= ? AND fecha_fin >= ?
             ORDER BY fecha_inicio
             LIMIT 1'
        );
        $consulta->execute([$unidadId, $excluirId, $temporada['fecha_fin'], $temporada['fecha_inicio']]);
        $otra = $consulta->fetch();
        if ($otra === false) {
            return null;
        }
        return sprintf(
            'Se superpone con la temporada %s del %s al %s',
            $otra['nombre'],
            ValidadorFechas::fecha($otra['fecha_inicio'])->format('d/m/Y'),
            ValidadorFechas::fecha($otra['fecha_fin'])->format('d/m/Y')
        );
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
