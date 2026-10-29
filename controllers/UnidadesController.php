<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\ReglasPublicacion;

//Alta y datos de una unidad (US2.1) y su depósito de garantía (US2.6).
//Las fotos de la unidad están en FotosUnidadController.
//La seña y la política de cancelación no se tocan acá: se copian de parametros al crear y se editan en el PR 3.
class UnidadesController
{
    //De qué clave de parametros sale cada columna de la seña y la política de una unidad nueva
    private const VALORES_POR_DEFECTO = [
        'sena_defecto'                 => 'porcentaje_sena',
        'politica_defecto_mas_de_30'   => 'retencion_mas_de_30',
        'politica_defecto_de_15_a_30'  => 'retencion_de_15_a_30',
        'politica_defecto_menos_de_15' => 'retencion_menos_de_15',
    ];

    //POST /unidades/crear: el propietario logueado da de alta una unidad. Queda sin publicar (borrador).
    public function crear()
    {
        Autorizacion::requiere('propietario');
        $datos = Request::datosJson();

        $unidad = $this->validarDatos($datos);
        if (isset($unidad['error'])) {
            return $this->error($unidad['error']);
        }
        $deposito = $this->validarDeposito($datos['deposito_garantia'] ?? null);
        if (is_array($deposito)) {
            return $this->error($deposito['error']);
        }

        //la seña y la política se copian (no se referencian): si el backoffice cambia el parámetro, esta unidad no cambia
        $valores = $this->valoresPorDefecto();

        $consulta = Conexion::obtener()->prepare(
            'INSERT INTO unidades (propietario_id, localidad_id, nombre, direccion, capacidad, caracteristicas, deposito_garantia,
                                   porcentaje_sena, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $consulta->execute([
            $_SESSION['usuario']['id'],//el dueño sale siempre de la sesión, nunca de lo que manda el front
            $unidad['localidad_id'],
            $unidad['nombre'],
            $unidad['direccion'],
            $unidad['capacidad'],
            $unidad['caracteristicas'],
            $deposito,
            $valores['porcentaje_sena'],
            $valores['retencion_mas_de_30'],
            $valores['retencion_de_15_a_30'],
            $valores['retencion_menos_de_15'],
        ]);

        http_response_code(201);
        echo json_encode(['ok' => 'Diste de alta tu unidad', 'id' => (int) Conexion::obtener()->lastInsertId()]);
    }

    //GET /unidades/mias: las unidades del propietario logueado, de la más nueva a la más vieja, para "Mis hospedajes"
    public function mias()
    {
        Autorizacion::requiere('propietario');

        //foto principal = la de orden 0; sin fila en administraciones = autogestión
        $consulta = Conexion::obtener()->prepare(
            'SELECT u.id, u.nombre, l.nombre AS localidad, l.provincia, u.estado_publicacion, u.disponible,
                    u.deposito_garantia, f.url AS foto_principal,
                    ad.nombre AS administrador_nombre, a.porcentaje_comision
             FROM unidades u
             JOIN localidades l ON l.id = u.localidad_id
             LEFT JOIN fotos_unidad f ON f.unidad_id = u.id AND f.orden = 0
             LEFT JOIN administraciones a ON a.unidad_id = u.id
             LEFT JOIN usuarios ad ON ad.id = a.administrador_id
             WHERE u.propietario_id = ?
             ORDER BY u.fecha_alta DESC, u.id DESC'
        );
        $consulta->execute([$_SESSION['usuario']['id']]);

        $unidades = [];
        foreach ($consulta->fetchAll() as $fila) {
            $unidades[] = [
                'id'                 => (int) $fila['id'],
                'nombre'             => $fila['nombre'],
                'localidad'          => $fila['localidad'],
                'provincia'          => $fila['provincia'],
                'estado_publicacion' => $fila['estado_publicacion'],
                'disponible'         => (bool) $fila['disponible'],//MySQL lo devuelve como 1 o 0
                'foto_principal'     => $fila['foto_principal'],
                'administrador'      => $fila['administrador_nombre'] === null ? null : [
                    'nombre'              => $fila['administrador_nombre'],
                    'porcentaje_comision' => $fila['porcentaje_comision'],
                ],
                'deposito_garantia'  => $fila['deposito_garantia'],
                'faltantes'          => ReglasPublicacion::faltantes((int) $fila['id']),
            ];
        }

        http_response_code(200);
        echo json_encode(['unidades' => $unidades]);
    }

    //GET /unidades/datos?id=5: todo lo editable de la unidad y sus fotos, para datos-unidad.html.
    //La ve el propietario y el administrador asignado (que solo puede cambiar el depósito: por eso va es_propietario).
    public function datos()
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador(Request::parametroGet('id'));//si no puede, responde y corta acá

        http_response_code(200);
        echo json_encode(['unidad' => [
            'id'                 => (int) $unidad['id'],
            'nombre'             => $unidad['nombre'],
            'localidad_id'       => (int) $unidad['localidad_id'],
            'direccion'          => $unidad['direccion'],
            'capacidad'          => (int) $unidad['capacidad'],
            'caracteristicas'    => $unidad['caracteristicas'],
            'deposito_garantia'  => $unidad['deposito_garantia'],
            'estado_publicacion' => $unidad['estado_publicacion'],
            'es_propietario'     => $unidad['es_propietario'],
            'fotos'              => FotosUnidadController::fotosDe((int) $unidad['id']),
        ]]);
    }

    //POST /unidades/editar: el propietario cambia los datos básicos de su unidad.
    //No toca el estado, la disponibilidad, el operador, la seña, la política ni el depósito (tiene su propia ruta).
    public function editar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');//el administrador recibe 404

        $nuevos = $this->validarDatos($datos);
        if (isset($nuevos['error'])) {
            return $this->error($nuevos['error']);
        }

        $consulta = Conexion::obtener()->prepare(
            'UPDATE unidades SET nombre = ?, localidad_id = ?, direccion = ?, capacidad = ?, caracteristicas = ? WHERE id = ?'
        );
        $consulta->execute([
            $nuevos['nombre'],
            $nuevos['localidad_id'],
            $nuevos['direccion'],
            $nuevos['capacidad'],
            $nuevos['caracteristicas'],
            $unidad['id'],
        ]);

        http_response_code(200);
        echo json_encode(['ok' => 'Guardamos los cambios']);
    }

    //POST /unidades/deposito: el propietario o el administrador cargan el depósito de garantía (US2.6).
    //Es informativo: se cobra y se devuelve por fuera de la aplicación (RN-04).
    public function deposito()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietarioOAdministrador($datos['unidad_id'] ?? '');

        $deposito = $this->validarDeposito($datos['deposito_garantia'] ?? null);
        if (is_array($deposito)) {
            return $this->error($deposito['error']);
        }

        $consulta = Conexion::obtener()->prepare('UPDATE unidades SET deposito_garantia = ? WHERE id = ?');
        $consulta->execute([$deposito, $unidad['id']]);

        http_response_code(200);
        echo json_encode(['ok' => 'Guardamos el depósito', 'deposito_garantia' => $deposito]);
    }

    //Valida los datos básicos de la unidad (los mismos al crear y al editar).
    //Devuelve los datos limpios, listos para guardar, o ['error' => mensaje].
    private function validarDatos(array $datos): array
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $direccion = trim((string) ($datos['direccion'] ?? ''));
        $caracteristicas = trim((string) ($datos['caracteristicas'] ?? ''));
        $capacidad = is_bool($datos['capacidad'] ?? null)//filter_var tomaría true como 1
            ? false
            : filter_var($datos['capacidad'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);

        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 100) {
            return ['error' => 'El nombre tiene que tener entre 3 y 100 caracteres'];
        }
        $localidadId = $this->localidadActiva($datos['localidad_id'] ?? '');
        if ($localidadId === null) {
            return ['error' => 'Elegí una localidad de la lista'];
        }
        if (mb_strlen($direccion) < 5 || mb_strlen($direccion) > 200) {
            return ['error' => 'La dirección tiene que tener entre 5 y 200 caracteres'];
        }
        if ($capacidad === false) {
            return ['error' => 'La capacidad tiene que ser de 1 a 50 personas'];
        }
        if (mb_strlen($caracteristicas) > 2000) {
            return ['error' => 'Las características pueden tener hasta 2000 caracteres'];
        }

        return [
            'nombre'          => $nombre,
            'localidad_id'    => $localidadId,
            'direccion'       => $direccion,
            'capacidad'       => $capacidad,
            'caracteristicas' => $caracteristicas === '' ? null : $caracteristicas,//vacío = sin características
        ];
    }

    //El id de la localidad si existe y está activa; si no, null (una localidad dada de baja no recibe unidades nuevas)
    private function localidadActiva(mixed $valor): ?int
    {
        $id = is_bool($valor) ? false : filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        $consulta = Conexion::obtener()->prepare('SELECT 1 FROM localidades WHERE id = ? AND activa = 1');
        $consulta->execute([$id]);
        return $consulta->fetchColumn() ? $id : null;
    }

    //Valida el depósito de garantía: null, ausente o "" = sin depósito (devuelve null).
    //Si no, un monto >= 0 con hasta 2 decimales que entre en decimal(12,2): lo devuelve como texto con 2 decimales
    //("15000.5" → "15000.50"), igual que lo devuelve PDO. Si no es válido, devuelve ['error' => mensaje].
    private function validarDeposito(mixed $valor): string|array|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = (is_int($valor) || is_float($valor) || is_string($valor)) ? trim((string) $valor) : '';
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $texto)) {
            return ['error' => 'El depósito tiene que ser un monto en pesos, con hasta 2 decimales'];
        }
        [$enteros, $decimales] = array_pad(explode('.', $texto), 2, '');
        return (int) $enteros . '.' . str_pad($decimales, 2, '0');//(int) saca los ceros de adelante: "007" → "7"
    }

    //Los 4 valores de parametros que recibe una unidad nueva, con el nombre de su columna en unidades
    private function valoresPorDefecto(): array
    {
        $claves = array_keys(self::VALORES_POR_DEFECTO);
        $marcadores = implode(', ', array_fill(0, count($claves), '?'));
        $consulta = Conexion::obtener()->prepare("SELECT clave, valor FROM parametros WHERE clave IN ($marcadores)");
        $consulta->execute($claves);
        $parametros = $consulta->fetchAll(\PDO::FETCH_KEY_PAIR);

        $valores = [];
        foreach (self::VALORES_POR_DEFECTO as $clave => $columna) {
            if (!isset($parametros[$clave])) {
                //no debería pasar: la migración de parametros los carga
                throw new \RuntimeException("Falta el parámetro $clave");
            }
            $valores[$columna] = $parametros[$clave];
        }
        return $valores;
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
