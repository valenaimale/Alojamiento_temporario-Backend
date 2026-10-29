<?php

namespace App\Controllers;

use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\Estadia;
use App\Validacion\ValidadorFechas;

//El detalle de una unidad para el huésped (US3.2) y su calendario. Es público: se usa con o sin sesión.
//Solo muestra unidades publicadas y disponibles: cualquier otra "no existe" (404).
//No muestra la dirección exacta (se le da al huésped cuando reserva, Épica 4) ni el motivo de las noches ocupadas.
class AlojamientoController
{
    private const MESES_HACIA_ADELANTE = 24;   //el calendario se puede ver hasta dos años hacia adelante

    //GET /alojamiento?id=5&ingreso=2026-12-05&egreso=2026-12-10&personas=4
    //Los datos de la unidad. Si vienen las fechas, además si se puede reservar y cuánto sale (con la seña).
    //Las personas son opcionales: si vienen, también se controla la capacidad.
    public function detalle()
    {
        $unidad = $this->unidadPublicada(Request::parametroGet('id'));
        if ($unidad === null) {
            return $this->error('No encontramos ese alojamiento', 404);
        }

        $estadia = null;
        if (Request::parametroGet('ingreso') !== '' || Request::parametroGet('egreso') !== '') {
            $fechas = Estadia::validarFechas(Request::parametroGet('ingreso'), Request::parametroGet('egreso'));
            if (isset($fechas['error'])) {
                return $this->error($fechas['error']);
            }
            $estadia = $this->estadia($unidad, $fechas, Request::parametroGet('personas'));
            if (isset($estadia['error'])) {
                return $this->error($estadia['error']);
            }
        }

        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare('SELECT url FROM fotos_unidad WHERE unidad_id = ? ORDER BY orden');
        $consulta->execute([$unidad['id']]);
        $fotos = $consulta->fetchAll(\PDO::FETCH_COLUMN);
        $consulta = $pdo->prepare("SELECT valor FROM parametros WHERE clave = 'dias_cobro_saldo'");
        $consulta->execute();

        http_response_code(200);
        echo json_encode([
            'alojamiento' => [
                'id'                => (int) $unidad['id'],
                'nombre'            => $unidad['nombre'],
                'localidad'         => $unidad['localidad'],
                'provincia'         => $unidad['provincia'],
                'capacidad'         => (int) $unidad['capacidad'],
                'caracteristicas'   => $unidad['caracteristicas'],
                'fotos'             => $fotos,//la primera es la principal
                'deposito_garantia' => $unidad['deposito_garantia'],//informativo: se cobra y se devuelve por fuera (US2.6)
                'porcentaje_sena'   => $unidad['porcentaje_sena'],
                'politica'          => [
                    'retencion_mas_de_30'   => $unidad['retencion_mas_de_30'],
                    'retencion_de_15_a_30'  => $unidad['retencion_de_15_a_30'],
                    'retencion_menos_de_15' => $unidad['retencion_menos_de_15'],
                    'dias_cobro_saldo'      => (int) $consulta->fetchColumn(),
                ],
            ],
            'estadia' => $estadia,//null si no vinieron fechas
        ]);
    }

    //GET /alojamiento/calendario?id=5&mes=2026-12: cada noche del mes con su estado, para que el huésped elija
    //sus fechas. Estados: libre (con su precio y el mínimo de noches de su temporada), ocupada, sin_precio y pasada
    //(hoy también: ya no se puede reservar). Solo "libre" se puede elegir como noche de la estadía.
    public function calendario()
    {
        $unidad = $this->unidadPublicada(Request::parametroGet('id'));
        if ($unidad === null) {
            return $this->error('No encontramos ese alojamiento', 404);
        }

        $mes = \DateTimeImmutable::createFromFormat('!Y-m', Request::parametroGet('mes'), new \DateTimeZone('America/Argentina/Buenos_Aires'));
        if ($mes === false || $mes->format('Y-m') !== Request::parametroGet('mes')) {
            return $this->error('El mes tiene que tener el formato AAAA-MM');
        }
        $hoy = ValidadorFechas::hoy();
        $esteMes = $hoy->modify('first day of this month');
        if ($mes < $esteMes || $mes > $esteMes->modify('+' . self::MESES_HACIA_ADELANTE . ' months')) {
            return $this->error('Podés ver desde este mes hasta dentro de dos años');
        }

        $primera = $mes->format('Y-m-d');
        $ultima = $mes->modify('last day of this month')->format('Y-m-d');
        $pdo = Conexion::obtener();

        $consulta = $pdo->prepare(
            'SELECT fecha, precio FROM precio_noche WHERE unidad_id = ? AND fecha BETWEEN ? AND ? AND vigente_hasta IS NULL'
        );
        $consulta->execute([$unidad['id'], $primera, $ultima]);
        $precios = $consulta->fetchAll(\PDO::FETCH_KEY_PAIR);//fecha → precio

        $consulta = $pdo->prepare(
            'SELECT fecha_inicio, fecha_fin, minimo_noches FROM temporadas WHERE unidad_id = ? AND fecha_inicio <= ? AND fecha_fin >= ?'
        );
        $consulta->execute([$unidad['id'], $ultima, $primera]);
        $temporadas = $consulta->fetchAll();

        $ocupadas = Estadia::nochesOcupadas((int) $unidad['id'], $primera, $ultima);

        $noches = [];
        for ($noche = $mes; $noche->format('Y-m-d') <= $ultima; $noche = $noche->modify('+1 day')) {
            $fecha = $noche->format('Y-m-d');
            $estado = match (true) {
                $noche <= $hoy             => 'pasada',
                isset($ocupadas[$fecha])   => 'ocupada',
                !isset($precios[$fecha])   => 'sin_precio',
                default                    => 'libre',
            };
            $noches[] = [
                'fecha'         => $fecha,
                'estado'        => $estado,
                'precio'        => $estado === 'libre' ? $precios[$fecha] : null,
                'minimo_noches' => $this->minimoDe($fecha, $temporadas),
            ];
        }

        http_response_code(200);
        echo json_encode(['noches' => $noches]);
    }

    //La unidad publicada y disponible con ese id, con su localidad; o null si no existe, no está publicada
    //o está fuera de servicio (para el huésped, en esos casos la unidad "no existe")
    private function unidadPublicada(string $textoId): ?array
    {
        $id = filter_var($textoId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        $consulta = Conexion::obtener()->prepare(
            "SELECT u.*, l.nombre AS localidad, l.provincia
             FROM unidades u
             JOIN localidades l ON l.id = u.localidad_id
             WHERE u.id = ? AND u.estado_publicacion = 'publicada' AND u.disponible = 1"
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();
        return $fila === false ? null : $fila;
    }

    //Si la estadía se puede reservar y cuánto sale, con la seña. Si vienen personas y no entran, no está disponible.
    //Devuelve ['error' => mensaje] si las personas no son un número válido.
    private function estadia(array $unidad, array $fechas, string $textoPersonas): array
    {
        if ($textoPersonas !== '') {
            $personas = filter_var($textoPersonas, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
            if ($personas === false) {
                return ['error' => 'Indicá cuántas personas van a ser (de 1 a 50)'];
            }
            if ($personas > (int) $unidad['capacidad']) {
                return ['disponible' => false, 'motivo' => "Tiene lugar para {$unidad['capacidad']} personas"];
            }
        }

        $estadia = Estadia::calcular((int) $unidad['id'], $fechas['ingreso'], $fechas['egreso']);
        if ($estadia['disponible']) {
            $estadia['sena'] = Estadia::sena($estadia['precio_total'], $unidad['porcentaje_sena']);
        }
        return $estadia;
    }

    //El mínimo de noches de la temporada que incluye esa noche, o 1 si no está en ninguna
    private function minimoDe(string $fecha, array $temporadas): int
    {
        foreach ($temporadas as $temporada) {
            if ($temporada['fecha_inicio'] <= $fecha && $fecha <= $temporada['fecha_fin']) {//AAAA-MM-DD se compara como texto
                return (int) $temporada['minimo_noches'];
            }
        }
        return 1;
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
