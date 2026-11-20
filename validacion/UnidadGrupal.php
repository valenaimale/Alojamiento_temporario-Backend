<?php

namespace App\Validacion;

use App\Database\Conexion;

//Las reglas de una unidad grupal (US4.4): qué unidades puede tener y cómo se arma para una búsqueda.
//Decisiones del 20/11 (docs/api/unidades-grupales.md):
//  - la arma un administrador, con 2 a 10 unidades que administra (el contrato paga hasta 10 en una transacción),
//    todas de la misma localidad. Lleva solo un título: se muestra la descripción de cada unidad;
//  - si deja de administrar una unidad, esa unidad sale sola del grupo (vigentes);
//  - para unas fechas y una cantidad de personas, la grupal ofrece SOLO las unidades libres que cumplen su mínimo de
//    noches. Aparece si quedan al menos 2, si entre todas alcanzan para las personas y si ninguna alcanza sola
//    (en ese caso le sirve una unidad suelta, que ya aparece en la búsqueda).
class UnidadGrupal
{
    public const MINIMO_UNIDADES = 2;
    public const MAXIMO_UNIDADES = 10;//el mismo tope que MAXIMO_GRUPO del contrato
    private const LARGO_TITULO = 100;

    //Valida lo que manda el administrador para crear o editar una grupal: {"titulo", "unidad_ids": [..]}.
    //Devuelve ['error' => mensaje] o ['titulo', 'unidad_ids', 'localidad_id'] listos para guardar.
    public static function validar(array $datos, int $administradorId): array
    {
        $titulo = trim((string) ($datos['titulo'] ?? ''));
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > self::LARGO_TITULO) {
            return ['error' => 'El título tiene que tener entre 3 y ' . self::LARGO_TITULO . ' caracteres'];
        }

        $ids = $datos['unidad_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) {
            return ['error' => 'Elegí las unidades de la reserva grupal'];
        }
        $unidadIds = [];
        foreach ($ids as $id) {
            $entero = is_bool($id) ? false : filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($entero === false) {
                return ['error' => 'Elegí las unidades de la reserva grupal'];
            }
            $unidadIds[$entero] = $entero;//sin repetidas
        }
        $unidadIds = array_values($unidadIds);
        if (count($unidadIds) < self::MINIMO_UNIDADES || count($unidadIds) > self::MAXIMO_UNIDADES) {
            return ['error' => 'Una reserva grupal tiene de ' . self::MINIMO_UNIDADES . ' a ' . self::MAXIMO_UNIDADES . ' unidades'];
        }

        //todas tienen que ser suyas (las administra hoy) y de la misma localidad
        $marcas = implode(', ', array_fill(0, count($unidadIds), '?'));
        $consulta = Conexion::obtener()->prepare(
            "SELECT u.id, u.localidad_id FROM unidades u
             JOIN administraciones a ON a.unidad_id = u.id AND a.administrador_id = ?
             WHERE u.id IN ($marcas)"
        );
        $consulta->execute([$administradorId, ...$unidadIds]);
        $localidades = $consulta->fetchAll(\PDO::FETCH_KEY_PAIR);//id → localidad
        if (count($localidades) !== count($unidadIds)) {
            return ['error' => 'Solo podés agrupar unidades que administrás'];
        }
        if (count(array_unique($localidades)) > 1) {
            return ['error' => 'Todas las unidades de una reserva grupal tienen que ser de la misma localidad'];
        }

        return ['titulo' => $titulo, 'unidad_ids' => $unidadIds, 'localidad_id' => (int) reset($localidades)];
    }

    //Las unidades de la grupal que su administrador sigue administrando, por nombre: las únicas que cuentan
    //(si deja de administrar una, sale sola del grupo). Cada una con lo que hace falta para buscarla y mostrarla.
    public static function vigentes(int $grupalId): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT u.id, u.nombre, u.capacidad, u.estado_publicacion, u.disponible, u.localidad_id,
                    p.nombre AS propietario,
                    (SELECT f.url FROM fotos_unidad f WHERE f.unidad_id = u.id AND f.orden = 0) AS foto_principal
             FROM unidades_grupales g
             JOIN unidades_grupales_unidades gu ON gu.grupal_id = g.id
             JOIN unidades u ON u.id = gu.unidad_id
             JOIN administraciones a ON a.unidad_id = u.id AND a.administrador_id = g.administrador_id
             JOIN usuarios p ON p.id = u.propietario_id
             WHERE g.id = ?
             ORDER BY u.nombre, u.id"
        );
        $consulta->execute([$grupalId]);
        return $consulta->fetchAll();
    }

    //La grupal armada para esas fechas y esas personas, o null si no corresponde mostrarla (ver arriba).
    //Devuelve ['unidades' => [cada una con su estadía], 'capacidad', 'noches', 'importe_sin_descuento', 'precio_total'].
    //Solo cuentan las unidades publicadas y disponibles (las mismas que puede reservar un huésped).
    public static function armar(int $grupalId, \DateTimeImmutable $ingreso, \DateTimeImmutable $egreso, int $personas): ?array
    {
        $libres = [];
        foreach (self::vigentes($grupalId) as $unidad) {
            if ($unidad['estado_publicacion'] !== 'publicada' || !$unidad['disponible']) {
                continue;
            }
            $estadia = Estadia::calcular((int) $unidad['id'], $ingreso, $egreso);
            if (!$estadia['disponible']) {
                continue;//ocupada, sin precio o sin el mínimo de noches de su temporada: queda afuera del grupo
            }
            $libres[] = $unidad + ['estadia' => $estadia];
        }

        $capacidades = array_map(fn ($u) => (int) $u['capacidad'], $libres);
        if (count($libres) < self::MINIMO_UNIDADES || array_sum($capacidades) < $personas || max($capacidades) >= $personas) {
            return null;
        }

        $sinDescuento = '0.00';
        $total = '0.00';
        foreach ($libres as $unidad) {
            $sinDescuento = bcadd($sinDescuento, $unidad['estadia']['importe_sin_descuento'], 2);
            $total = bcadd($total, $unidad['estadia']['precio_total'], 2);
        }
        return [
            'unidades'              => $libres,
            'capacidad'             => array_sum($capacidades),
            'noches'                => $libres[0]['estadia']['noches'],
            'importe_sin_descuento' => $sinDescuento,
            'precio_total'          => $total,
        ];
    }
}
