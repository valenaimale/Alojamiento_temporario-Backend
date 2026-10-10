<?php

namespace App\Controllers;
use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use PDO;

//Administración interna de la plataforma (Tarea 2): el backoffice ve a todos los usuarios,
//los busca y filtra, ve su detalle y deshabilita o reactiva cuentas.
//Es el rol 'backoffice', NO 'administrador' (ese es el administrador de hospedajes).
//Todos los métodos empiezan con Autorizacion::requiere('backoffice').
class BackofficeController{
    private const POR_PAGINA = 20;
    //Los roles que se pueden usar como filtro. Si llega otra cosa, el filtro se ignora.
    private const ROLES = ['huesped', 'propietario', 'administrador', 'operador', 'backoffice'];

    //GET /backoffice/usuarios?buscar=&rol=&activo=&pagina= — todos los parámetros son opcionales
    public function listar(){
        Autorizacion::requiere('backoffice');//sin sesión 401, sin permiso 403, y corta acá

        $buscar = trim($_GET['buscar'] ?? '');
        $rol    = $_GET['rol'] ?? '';
        $activo = $_GET['activo'] ?? '';
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));//(int) de algo que no es número da 0, y max lo lleva a 1

        //El WHERE se arma con dos arrays: las condiciones son texto fijo del código y los valores
        //viajan siempre como parámetros, así no hay forma de inyectar SQL desde la búsqueda.
        $condiciones = [];
        $parametros  = [];

        if($buscar !== ''){
            $condiciones[] = '(u.nombre LIKE ? OR u.mail LIKE ? OR u.dni LIKE ?)';
            $comodin = '%' . $buscar . '%';
            $parametros[] = $comodin;
            $parametros[] = $comodin;
            $parametros[] = $comodin;
        }
        if(in_array($rol, self::ROLES, true)){
            $condiciones[] = 'u.rol = ?';
            $parametros[]  = $rol;
        }
        if($activo === '1' || $activo === '0'){
            $condiciones[] = 'u.activo = ?';
            $parametros[]  = (int) $activo;
        }
        $where = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';

        $pdo = Conexion::obtener();

        //cuántos usuarios hay en total con esos filtros (para armar la paginación)
        $consultaTotal = $pdo->prepare('SELECT COUNT(*) FROM usuarios u' . $where);
        $consultaTotal->execute($parametros);
        $total = (int) $consultaTotal->fetchColumn();

        //LIMIT y OFFSET no se pueden pasar como parámetros comunes: van con bindValue como enteros.
        //Todos los marcadores son ? (PDO no permite mezclar ? con :nombre en la misma consulta).
        $consulta = $pdo->prepare(
            'SELECT u.id, u.nombre, u.mail, u.dni, u.rol, u.activo, u.mail_verificado_en, u.fecha_alta
             FROM usuarios u
             LEFT JOIN propietarios p ON p.usuario_id = u.id'
            . $where .
            ' ORDER BY u.id DESC LIMIT ? OFFSET ?'
        );
        foreach($parametros as $posicion => $valor){
            $consulta->bindValue($posicion + 1, $valor);//los ? se numeran desde 1, el array desde 0
        }
        $consulta->bindValue(count($parametros) + 1, self::POR_PAGINA, PDO::PARAM_INT);
        $consulta->bindValue(count($parametros) + 2, ($pagina - 1) * self::POR_PAGINA, PDO::PARAM_INT);
        $consulta->execute();

        $usuarios = [];
        foreach($consulta->fetchAll() as $fila){
            $usuarios[] = $this->formatearUsuario($fila);
        }

        return $this->respuesta([
            'usuarios'   => $usuarios,
            'pagina'     => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'total'      => $total,
        ]);
    }

    //GET /backoffice/usuario?id=N
    public function detalle(){
        Autorizacion::requiere('backoffice');

        $id = (int) ($_GET['id'] ?? 0);
        if($id <= 0){
            return $this->error('Usuario inválido');
        }

        $usuario = $this->buscarUsuario($id);
        if($usuario === null){
            return $this->error('El usuario no existe', 404);
        }

        return $this->respuesta(['usuario' => $usuario]);
    }

    //POST /backoffice/usuario/estado — recibe {"id": 9, "activo": false}
    public function cambiarEstado(){
        Autorizacion::requiere('backoffice');

        $datos  = json_decode(file_get_contents('php://input'), true);
        $id     = (int) ($datos['id'] ?? 0);
        $activo = $datos['activo'] ?? null;

        if($id <= 0){
            return $this->error('Usuario inválido');
        }
        if(!is_bool($activo)){//tiene que llegar true o false, no "1" ni 1
            return $this->error('El estado es inválido');
        }
        //para que el backoffice no se quede afuera del sistema sin que nadie pueda reactivarlo
        if($id === (int) $_SESSION['usuario']['id']){
            return $this->error('No podés deshabilitar tu propia cuenta');
        }
        if($this->buscarUsuario($id) === null){
            return $this->error('El usuario no existe', 404);
        }

        $consulta = Conexion::obtener()->prepare('UPDATE usuarios SET activo = ? WHERE id = ?');
        $consulta->execute([$activo ? 1 : 0, $id]);

        //se devuelve el usuario ya actualizado, así el front muestra el estado nuevo sin pedirlo de nuevo
        return $this->respuesta([
            'ok'      => $activo ? 'Cuenta reactivada' : 'Cuenta deshabilitada',
            'usuario' => $this->buscarUsuario($id),
        ]);
    }

    //Busca un usuario con sus datos fiscales (si es propietario). Devuelve null si no existe.
    private function buscarUsuario(int $id): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT u.id, u.nombre, u.mail, u.dni, u.rol, u.activo, u.mail_verificado_en, u.fecha_alta,
                    p.cobra_iva, p.cuit, p.razon_social, p.domicilio_fiscal
             FROM usuarios u
             LEFT JOIN propietarios p ON p.usuario_id = u.id
             WHERE u.id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();//devuelve false si no encontro ninguna fila
        if(!$fila){
            return null;
        }

        $usuario = $this->formatearUsuario($fila);
        //si el usuario no tiene fila en propietarios, el LEFT JOIN deja las columnas fiscales en NULL
        $usuario['datos_fiscales'] = $fila['cobra_iva'] === null ? null : [
            'cobra_iva'        => (bool) $fila['cobra_iva'],
            'cuit'             => $fila['cuit'],
            'razon_social'     => $fila['razon_social'],
            'domicilio_fiscal' => $fila['domicilio_fiscal'],
        ];
        return $usuario;
    }

    //Pasa una fila de la base al formato que espera el front. Nunca incluye la contraseña.
    private function formatearUsuario(array $fila): array
    {
        return [
            'id'              => (int) $fila['id'],
            'nombre'          => $fila['nombre'],
            'mail'            => $fila['mail'],
            'dni'             => $fila['dni'],
            'rol'             => $fila['rol'],
            'activo'          => (bool) $fila['activo'],
            'mail_verificado' => $fila['mail_verificado_en'] !== null,
            'fecha_alta'      => $fila['fecha_alta'],
        ];
    }

    private function error(string $mensaje, int $codigo = 422){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
    private function respuesta(array $cuerpo, int $codigo = 200){
        http_response_code($codigo);
        echo json_encode($cuerpo);
    }
}
