<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Request\Request;
use App\Request\Respuesta;
use App\Validacion\Estadia;
use App\Validacion\UnidadGrupal;
use App\Validacion\VencimientoReserva;

//Las unidades grupales (US4.4): el administrador las arma con unidades que administra, y el huésped las encuentra
//en la búsqueda y ve su detalle. Las reglas están en validacion/UnidadGrupal.php y en docs/api/unidades-grupales.md.
class UnidadesGrupalesController
{
    //GET /administrador/grupales: las grupales del administrador logueado, con sus unidades.
    //De cada unidad dice si sigue en el grupo ('vigente'): si ya no la administra, sale sola.
    public function listar()
    {
        Autorizacion::requiere('administrador');
        $administradorId = (int) $_SESSION['usuario']['id'];

        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT g.id, g.titulo, g.activa, g.creado_en, l.nombre AS localidad, l.provincia
             FROM unidades_grupales g
             JOIN localidades l ON l.id = g.localidad_id
             WHERE g.administrador_id = ?
             ORDER BY g.titulo, g.id'
        );
        $consulta->execute([$administradorId]);
        $unidades = $pdo->prepare(
            'SELECT u.id, u.nombre, u.capacidad, u.estado_publicacion, p.nombre AS propietario,
                    (a.administrador_id IS NOT NULL) AS vigente
             FROM unidades_grupales_unidades gu
             JOIN unidades u ON u.id = gu.unidad_id
             JOIN usuarios p ON p.id = u.propietario_id
             LEFT JOIN administraciones a ON a.unidad_id = u.id AND a.administrador_id = ?
             WHERE gu.grupal_id = ?
             ORDER BY u.nombre, u.id'
        );

        $grupales = [];
        foreach ($consulta->fetchAll() as $fila) {
            $unidades->execute([$administradorId, $fila['id']]);
            $deLaGrupal = array_map(fn ($u) => [
                'id'                 => (int) $u['id'],
                'nombre'             => $u['nombre'],
                'capacidad'          => (int) $u['capacidad'],
                'propietario'        => $u['propietario'],
                'estado_publicacion' => $u['estado_publicacion'],
                'vigente'            => (bool) $u['vigente'],
            ], $unidades->fetchAll());
            $vigentes = array_filter($deLaGrupal, fn ($u) => $u['vigente']);
            $grupales[] = [
                'id'        => (int) $fila['id'],
                'titulo'    => $fila['titulo'],
                'activa'    => (bool) $fila['activa'],
                'localidad' => $fila['localidad'],
                'provincia' => $fila['provincia'],
                'capacidad' => array_sum(array_column($vigentes, 'capacidad')),
                //con menos de 2 unidades vigentes no aparece en la búsqueda aunque esté activa
                'incompleta' => count($vigentes) < UnidadGrupal::MINIMO_UNIDADES,
                'unidades'  => $deLaGrupal,
            ];
        }

        Respuesta::json(['grupales' => $grupales]);
    }

    //POST /administrador/grupales/crear: {"titulo", "unidad_ids": [4, 5, 6]}. Nace activa.
    public function crear()
    {
        Autorizacion::requiere('administrador');
        $administradorId = (int) $_SESSION['usuario']['id'];

        $grupal = UnidadGrupal::validar(Request::datosJson(['unidad_ids']), $administradorId);
        if (isset($grupal['error'])) {
            return Respuesta::error($grupal['error']);
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO unidades_grupales (administrador_id, localidad_id, titulo, activa, creado_en) VALUES (?, ?, ?, 1, ?)'
            )->execute([$administradorId, $grupal['localidad_id'], $grupal['titulo'], VencimientoReserva::ahora()]);
            $id = (int) $pdo->lastInsertId();
            $this->guardarUnidades($id, $grupal['unidad_ids']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Respuesta::json(['ok' => "Creaste la reserva grupal \"{$grupal['titulo']}\"", 'id' => $id], 201);
    }

    //POST /administrador/grupales/editar: {"id", "titulo", "unidad_ids": [..]}. Las reservas ya hechas no cambian.
    public function editar()
    {
        Autorizacion::requiere('administrador');
        $administradorId = (int) $_SESSION['usuario']['id'];
        $datos = Request::datosJson(['unidad_ids']);

        $id = $this->grupalPropia($datos['id'] ?? null, $administradorId);
        if ($id === null) {
            return Respuesta::error('No encontramos esa reserva grupal', 404);
        }
        $grupal = UnidadGrupal::validar($datos, $administradorId);
        if (isset($grupal['error'])) {
            return Respuesta::error($grupal['error']);
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE unidades_grupales SET titulo = ?, localidad_id = ? WHERE id = ?')
                ->execute([$grupal['titulo'], $grupal['localidad_id'], $id]);
            $pdo->prepare('DELETE FROM unidades_grupales_unidades WHERE grupal_id = ?')->execute([$id]);
            $this->guardarUnidades($id, $grupal['unidad_ids']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Respuesta::json(['ok' => 'Guardaste los cambios de la reserva grupal']);
    }

    //POST /administrador/grupales/estado: {"id", "activa": true|false}. Una desactivada no aparece en la búsqueda.
    //Para activarla tiene que tener al menos 2 unidades que siga administrando.
    public function cambiarEstado()
    {
        Autorizacion::requiere('administrador');
        $administradorId = (int) $_SESSION['usuario']['id'];
        $datos = Request::datosJson();

        $id = $this->grupalPropia($datos['id'] ?? null, $administradorId);
        if ($id === null) {
            return Respuesta::error('No encontramos esa reserva grupal', 404);
        }
        if (!is_bool($datos['activa'] ?? null)) {
            return Respuesta::error('Indicá si la reserva grupal queda activa o no');
        }
        if ($datos['activa'] && count(UnidadGrupal::vigentes($id)) < UnidadGrupal::MINIMO_UNIDADES) {
            return Respuesta::error('Para activarla tiene que tener al menos ' . UnidadGrupal::MINIMO_UNIDADES . ' unidades que administres', 409);
        }

        Conexion::obtener()->prepare('UPDATE unidades_grupales SET activa = ? WHERE id = ?')->execute([$datos['activa'] ? 1 : 0, $id]);
        Respuesta::json(['ok' => $datos['activa'] ? 'La reserva grupal aparece en la búsqueda' : 'La reserva grupal ya no aparece en la búsqueda']);
    }

    //GET /grupal?id=3&ingreso=2027-01-02&egreso=2027-01-09&personas=10: el detalle de una grupal para esas fechas.
    //Es público, como el detalle de una unidad. Trae solo las unidades libres (las mismas que muestra la búsqueda),
    //cada una completa (características, fotos, seña, política y precio), para que el huésped elija cuáles reservar.
    //Si para esas fechas y personas la grupal no corresponde (ver UnidadGrupal::armar), responde 409.
    public function detalle()
    {
        $id = Request::enteroDeLaUrl('id');
        $grupal = false;
        if ($id !== null) {
            $consulta = Conexion::obtener()->prepare(
                'SELECT g.id, g.titulo, l.nombre AS localidad, l.provincia
                 FROM unidades_grupales g
                 JOIN localidades l ON l.id = g.localidad_id
                 WHERE g.id = ? AND g.activa = 1 AND l.activa = 1'
            );
            $consulta->execute([$id]);
            $grupal = $consulta->fetch();
        }
        if ($grupal === false) {
            return Respuesta::error('No encontramos esa reserva grupal', 404);
        }

        $fechas = Estadia::validarFechas(Request::parametroGet('ingreso'), Request::parametroGet('egreso'));
        if (isset($fechas['error'])) {
            return Respuesta::error($fechas['error']);
        }
        $personas = Request::enteroDeLaUrl('personas', 1, 50);
        if ($personas === null) {
            return Respuesta::error('Indicá cuántas personas van a ser (de 1 a 50)');
        }

        $armada = UnidadGrupal::armar((int) $grupal['id'], $fechas['ingreso'], $fechas['egreso'], $personas);
        if ($armada === null) {
            return Respuesta::error('Esta reserva grupal no está disponible para esas fechas y esa cantidad de personas', 409);
        }

        Respuesta::json([
            'grupal' => [
                'id'                    => (int) $grupal['id'],
                'titulo'                => $grupal['titulo'],
                'localidad'             => $grupal['localidad'],
                'provincia'             => $grupal['provincia'],
                'capacidad'             => $armada['capacidad'],
                'noches'                => $armada['noches'],
                'importe_sin_descuento' => $armada['importe_sin_descuento'],
                'precio_total'          => $armada['precio_total'],
                'unidades'              => array_map(fn (array $unidad) => $this->unidadDelDetalle($unidad, $fechas['ingreso']), $armada['unidades']),
            ],
        ]);
    }

    //Una unidad del detalle de la grupal: lo mismo que el detalle de una unidad suelta, con su estadía y su seña
    //$ingreso: con 5 días o menos, la seña es el total (#75)
    private function unidadDelDetalle(array $unidad, \DateTimeImmutable $ingreso): array
    {
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT caracteristicas, deposito_garantia, porcentaje_sena, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15
             FROM unidades WHERE id = ?'
        );
        $consulta->execute([$unidad['id']]);
        $datos = $consulta->fetch();
        $consulta = $pdo->prepare('SELECT url FROM fotos_unidad WHERE unidad_id = ? ORDER BY orden');
        $consulta->execute([$unidad['id']]);

        $estadia = $unidad['estadia'];
        return [
            'id'                => (int) $unidad['id'],
            'nombre'            => $unidad['nombre'],
            'capacidad'         => (int) $unidad['capacidad'],
            'caracteristicas'   => $datos['caracteristicas'],
            'fotos'             => $consulta->fetchAll(\PDO::FETCH_COLUMN),
            'deposito_garantia' => $datos['deposito_garantia'],
            'porcentaje_sena'   => $datos['porcentaje_sena'],
            'politica'          => [
                'retencion_mas_de_30'   => $datos['retencion_mas_de_30'],
                'retencion_de_15_a_30'  => $datos['retencion_de_15_a_30'],
                'retencion_menos_de_15' => $datos['retencion_menos_de_15'],
            ],
            'estadia'           => [
                'noches'                => $estadia['noches'],
                'importe_sin_descuento' => $estadia['importe_sin_descuento'],
                'porcentaje_descuento'  => $estadia['porcentaje_descuento'],
                'precio_total'          => $estadia['precio_total'],
                'sena'                  => $sena = Estadia::senaAlReservar($estadia['precio_total'], $datos['porcentaje_sena'], $ingreso),
                'pago_total'            => bccomp($sena, $estadia['precio_total'], 2) === 0,
            ],
        ];
    }

    //Guarda las unidades de una grupal (dentro de la transacción de crear o editar)
    private function guardarUnidades(int $grupalId, array $unidadIds): void
    {
        $insercion = Conexion::obtener()->prepare('INSERT INTO unidades_grupales_unidades (grupal_id, unidad_id) VALUES (?, ?)');
        foreach ($unidadIds as $unidadId) {
            $insercion->execute([$grupalId, $unidadId]);
        }
    }

    //El id de la grupal si es del administrador, o null (la de otro "no existe": 404)
    private function grupalPropia(mixed $id, int $administradorId): ?int
    {
        $grupalId = Request::entero($id);
        if ($grupalId === null) {
            return null;
        }
        $consulta = Conexion::obtener()->prepare('SELECT id FROM unidades_grupales WHERE id = ? AND administrador_id = ?');
        $consulta->execute([$grupalId, $administradorId]);
        $fila = $consulta->fetchColumn();
        return $fila === false ? null : (int) $fila;
    }
}
