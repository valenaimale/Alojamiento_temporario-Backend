<?php

namespace App\Autorizacion;

use App\Database\Conexion;

//Único lugar que arma $_SESSION['usuario']: login, registro y OAuth lo usan para iniciar la sesión.
//Formato: {id, nombre, mail, dni, rol, mail_verificado}
//Los permisos de cada rol (el propietario hereda al huésped) los decide Autorizacion.php.
class SesionUsuario
{
    //Carga el usuario de la base con el formato de la sesión. Devuelve null si no existe.
    public static function cargar(int $usuarioId): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT id, nombre, mail, dni, rol, mail_verificado_en FROM usuarios WHERE id = ?'
        );
        $consulta->execute([$usuarioId]);
        $fila = $consulta->fetch();
        if (!$fila) {
            return null;
        }
        return [
            'id'              => (int) $fila['id'],
            'nombre'          => $fila['nombre'],
            'mail'            => $fila['mail'],
            'dni'             => $fila['dni'],
            'rol'             => $fila['rol'],
            'mail_verificado' => $fila['mail_verificado_en'] !== null,
        ];
    }

    //Inicia la sesión del usuario (login, registro, OAuth). Regenera el ID para evitar session fixation.
    public static function iniciar(int $usuarioId): array
    {
        $usuario = self::cargar($usuarioId);
        if ($usuario === null) {
            throw new \RuntimeException("No existe el usuario $usuarioId");
        }
        session_regenerate_id(true);
        $_SESSION['usuario'] = $usuario;
        return $usuario;
    }

    //Vuelve a cargar los datos de la sesión desde la base (después de cambiar algo del usuario logueado,
    //por ejemplo hacerse propietario, verificar el mail o completar el DNI)
    public static function refrescar(): void
    {
        if (!isset($_SESSION['usuario'])) {
            return;
        }
        $usuario = self::cargar($_SESSION['usuario']['id']);
        if ($usuario === null) {
            self::cerrar();
            return;
        }
        $_SESSION['usuario'] = $usuario;
    }

    //Destruye la sesión en el servidor y le pide al navegador que borre la cookie
    public static function cerrar(): void
    {
        $_SESSION = [];                                     // 1. vacía todos los datos de la sesión
        $parametros = session_get_cookie_params();          // 2. le pide al navegador que borre la cookie:
        setcookie(session_name(), '', [                     //    se la vuelve a mandar vacía y con una fecha
            'expires'  => time() - 3600,                    //    de vencimiento en el pasado (hace 1 hora)
            'path'     => $parametros['path'],
            'httponly' => $parametros['httponly'],
            'samesite' => $parametros['samesite'],
        ]);
        session_destroy();                                  // 3. borra la sesión del servidor
    }
}
