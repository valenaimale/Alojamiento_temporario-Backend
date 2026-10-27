<?php

namespace App\Autorizacion;

use App\Database\Conexion;

//PermisosUnidad controla si el usuario logueado puede trabajar sobre una unidad.
//Igual que Autorizacion, se llama en la primera línea del método del controlador:
//    $unidad = PermisosUnidad::delPropietario($_GET['id'] ?? '');
//Si el usuario no puede seguir, responde el error y corta la ejecución ahí. Si puede, devuelve la fila de la unidad.
//
//Si la unidad no existe o no es suya, responde 404 en los dos casos: así nadie puede averiguar
//qué unidades existen probando números (un 403 confirmaría que esa unidad existe).
class PermisosUnidad
{
    //Solo el propietario de la unidad: editar sus datos, condiciones comerciales, publicar, asignar administrador.
    public static function delPropietario(mixed $unidadId): array
    {
        Autorizacion::requiere('propietario');//sin sesión 401, otro rol 403
        $unidad = self::buscar(self::id($unidadId));

        if ($unidad === null || (int) $unidad['propietario_id'] !== (int) $_SESSION['usuario']['id']) {
            self::cortar(404, 'No encontramos esa unidad');
        }
        return $unidad + ['es_propietario' => true];
    }

    //El propietario o el administrador asignado a esa unidad: ver la unidad, el depósito, bloquear fechas.
    //La fila que devuelve trae es_propietario, para que el controlador sepa quién es.
    public static function delPropietarioOAdministrador(mixed $unidadId): array
    {
        Autorizacion::requiereSesion();//sin sesión 401
        $unidad = self::buscar(self::id($unidadId));
        $usuario = $_SESSION['usuario'];

        $esPropietario = $unidad !== null
            && $usuario['rol'] === 'propietario'
            && (int) $unidad['propietario_id'] === (int) $usuario['id'];
        $esAdministrador = $unidad !== null
            && $usuario['rol'] === 'administrador'
            && $unidad['administrador_id'] !== null
            && (int) $unidad['administrador_id'] === (int) $usuario['id'];

        if (!$esPropietario && !$esAdministrador) {
            self::cortar(404, 'No encontramos esa unidad');
        }
        return $unidad + ['es_propietario' => $esPropietario];
    }

    //El id llega del query string o del JSON, como texto o número: tiene que ser un entero positivo
    private static function id(mixed $valor): int
    {
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            self::cortar(422, 'Unidad inválida');
        }
        return $id;
    }

    //La unidad con su administrador (null si es de autogestión). Devuelve null si la unidad no existe.
    private static function buscar(int $id): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT u.*, a.administrador_id
             FROM unidades u
             LEFT JOIN administraciones a ON a.unidad_id = u.id
             WHERE u.id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();//devuelve false si no encontró ninguna fila
        return $fila === false ? null : $fila;
    }

    //Responde el error en JSON y termina la petición, igual que Autorizacion
    private static function cortar(int $codigo, string $mensaje): void
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
        exit;
    }
}
