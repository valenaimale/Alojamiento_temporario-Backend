<?php

namespace App\Autorizacion;

use App\Database\Conexion;

//Autorizacion controla quien puede usar cada ruta del back.
//Se llama en la primera linea del metodo del controlador, por ejemplo:
//    Autorizacion::requiere('propietario');
//Si el usuario no puede seguir, responde el error y corta la ejecucion ahi.
class Autorizacion
{
    //Para cada rol, los roles que "incluye".
    //El propietario es un huesped con funciones extra, asi que puede hacer
    //todo lo que hace un huesped. Operador, administrador y backoffice son cuentas aparte.
    private const PERMISOS = [
        'huesped'       => ['huesped'],
        'propietario'   => ['propietario', 'huesped'],
        'operador'      => ['operador'],
        'administrador' => ['administrador'],
        'backoffice'    => ['backoffice'],     //administración interna de la plataforma
    ];

    //Devuelve true si un usuario con $rolUsuario puede hacer lo que pide $rolRequerido.
    public static function tienePermiso(string $rolUsuario, string $rolRequerido): bool
    {
        return in_array($rolRequerido, self::PERMISOS[$rolUsuario] ?? [], true);
    }

    //Para rutas que puede usar cualquier usuario logueado, sin importar el rol.
    //Sin sesion responde 401. Si la cuenta fue deshabilitada mientras tenia la sesion abierta,
    //cierra la sesion y tambien responde 401 (se consulta la base en cada peticion protegida).
    //En la misma consulta actualiza si el mail esta verificado: el enlace del mail se puede abrir en otro
    //navegador (o en la app de mail), y sin esto la sesion seguiria diciendo "sin verificar" hasta volver a entrar.
    public static function requiereSesion(): void
    {
        if (!isset($_SESSION['usuario'])) {
            self::cortar(401, 'No hay sesión iniciada');
        }

        $consulta = Conexion::obtener()->prepare('SELECT activo, mail_verificado_en, dni, version_sesion FROM usuarios WHERE id = ?');
        $consulta->execute([$_SESSION['usuario']['id']]);
        $usuario = $consulta->fetch();
        if ($usuario === false || (int) $usuario['activo'] === 0) {
            SesionUsuario::cerrar();
            self::cortar(401, 'Tu cuenta está deshabilitada');
        }
        // Las sesiones previas a la migración equivalen a versión 0. Un reset revoca todas,
        // tanto en archivos como en Redis, sin enumerar ni borrar el almacenamiento de sesiones.
        if ((int) ($_SESSION['version_sesion'] ?? 0) !== (int) $usuario['version_sesion']) {
            SesionUsuario::cerrar();
            self::cortar(401, 'Tu contraseña cambió. Volvé a iniciar sesión.');
        }
        $_SESSION['usuario']['mail_verificado'] = $usuario['mail_verificado_en'] !== null;
        //El DNI se puede completar desde otra sesión, igual que la verificación del mail.
        $_SESSION['usuario']['dni'] = $usuario['dni'];
    }

    //Para rutas de un rol puntual.
    //Sin sesion responde 401. Con sesion pero sin permiso responde 403.
    public static function requiere(string $rolRequerido): void
    {
        self::requiereSesion();

        if (!self::tienePermiso($_SESSION['usuario']['rol'], $rolRequerido)) {
            self::cortar(403, 'No tenés permiso para hacer esto');
        }
    }

    //Para acciones que exigen el mail verificado (por ejemplo, reservar). Sin verificar responde 403.
    public static function requiereMailVerificado(): void
    {
        self::requiereSesion();

        if (!$_SESSION['usuario']['mail_verificado']) {
            self::cortar(403, 'Tenés que verificar tu mail para hacer esto');
        }
    }

    //Responde el error en JSON y termina la petición: así el controlador no sigue después de un permiso denegado
    private static function cortar(int $codigo, string $mensaje): void
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
        exit;
    }
}
