<?php

namespace App\Controllers;

use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\Estadia;

//La búsqueda de unidades disponibles (US3.2). Es pública: se usa con o sin sesión.
//Fechas obligatorias; localidad opcional (sin localidad, busca en todos los destinos).
class BusquedaController
{
    private const MAXIMO_PERSONAS = 50;

    //GET /buscar?localidad_id=1&ingreso=2026-12-05&egreso=2026-12-10&personas=4
    //Las unidades publicadas, disponibles, con capacidad suficiente y que se pueden reservar en esas fechas
    //(Estadia::calcular), con el precio total. Ordenadas de la más barata a la más cara.
    public function buscar()
    {
        $fechas = Estadia::validarFechas(Request::parametroGet('ingreso'), Request::parametroGet('egreso'));
        if (isset($fechas['error'])) {
            return $this->error($fechas['error']);
        }
        $personas = filter_var(Request::parametroGet('personas'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAXIMO_PERSONAS],
        ]);
        if ($personas === false) {
            return $this->error('Indicá cuántas personas van a ser (de 1 a ' . self::MAXIMO_PERSONAS . ')');
        }

        $pdo = Conexion::obtener();
        $localidadId = null;
        if (Request::parametroGet('localidad_id') !== '') {
            $localidadId = filter_var(Request::parametroGet('localidad_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $consulta = $pdo->prepare('SELECT 1 FROM localidades WHERE id = ? AND activa = 1');
            $consulta->execute([$localidadId === false ? 0 : $localidadId]);
            if ($localidadId === false || !$consulta->fetchColumn()) {
                return $this->error('Elegí una localidad de la lista');
            }
        }

        //las candidatas: publicadas, disponibles, en una localidad activa y con lugar para todos
        $sql = 'SELECT u.id, u.nombre, u.capacidad, l.nombre AS localidad, l.provincia,
                       (SELECT f.url FROM fotos_unidad f WHERE f.unidad_id = u.id AND f.orden = 0) AS foto_principal
                FROM unidades u
                JOIN localidades l ON l.id = u.localidad_id
                WHERE u.estado_publicacion = \'publicada\' AND u.disponible = 1 AND l.activa = 1 AND u.capacidad >= ?';
        $parametros = [$personas];
        if ($localidadId !== null) {
            $sql .= ' AND u.localidad_id = ?';
            $parametros[] = $localidadId;
        }
        $consulta = $pdo->prepare($sql);
        $consulta->execute($parametros);

        //de las candidatas, las que se pueden reservar en esas fechas (unas pocas consultas por unidad:
        //alcanza para la cantidad de unidades de la plataforma; si crece, se puede pasar a una sola consulta)
        $unidades = [];
        foreach ($consulta->fetchAll() as $unidad) {
            $estadia = Estadia::calcular((int) $unidad['id'], $fechas['ingreso'], $fechas['egreso']);
            if (!$estadia['disponible']) {
                continue;
            }
            $unidades[] = [
                'id'             => (int) $unidad['id'],
                'nombre'         => $unidad['nombre'],
                'localidad'      => $unidad['localidad'],
                'provincia'      => $unidad['provincia'],
                'capacidad'      => (int) $unidad['capacidad'],
                'foto_principal' => $unidad['foto_principal'],
                'noches'         => $estadia['noches'],
                'precio_total'   => $estadia['precio_total'],
            ];
        }
        usort($unidades, fn ($a, $b) => (float) $a['precio_total'] <=> (float) $b['precio_total']);

        http_response_code(200);
        echo json_encode(['unidades' => $unidades]);
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
