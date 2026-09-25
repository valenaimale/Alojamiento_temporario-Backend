<?php

namespace App\Controllers;
use App\Database\Conexion;

class InicioSesionController{
    public function iniciaSesion(){
        //se encarga del post de iniciar sesion
        $datos_inicio_sesion = json_decode(file_get_contents('php://input'), true);
        $mail= strtolower(trim($datos_inicio_sesion['mail'] ?? ''));
        $contrasenia = $datos_inicio_sesion['contrasenia'] ?? '';
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT u.contrasenia, u.id, u.rol, u.nombre from usuarios u WHERE u.mail=? LIMIT 1'
        );
        $consulta->execute([$mail]);
        $fila=$consulta->fetch();//devuelve false si no encontro ninguna fila
        if(!$fila || !password_verify($contrasenia, $fila['contrasenia'])){
            return $this->error('Mail o contraseña incorrectos');
        }
        else{
            session_regenerate_id(true);   // nuevo ID de sesión al loguearse (evita session fixation)
            $_SESSION['usuario'] = [
            'id'     => $fila['id'],
            'nombre' => $fila['nombre'],
            'rol'    => $fila['rol'],
            ];
            return $this->respuestaInicioSesion('Sesión iniciada', $_SESSION['usuario']);
        }
    }
    public function cierraSesion(){
        $_SESSION = [];           // 1. vacía todos los datos de la sesión

        $parametros = session_get_cookie_params();                  // 2. le pide al navegador que borre la cookie:
        setcookie(session_name(), '', [                          //    se la vuelve a mandar vacía y con una fecha
            'expires'  => time() - 3600,                         //    de vencimiento en el pasado (hace 1 hora)
            'path'     => $parametros['path'],
            'httponly' => $parametros['httponly'],
            'samesite' => $parametros['samesite'],
        ]);
        session_destroy();        // 3. borra el archivo sess_... del servidor
        return $this->respuestaSesionCerrada('Sesión cerrada');
    }
    public function dameSesion(){//el front va a pegar contra esta url cuando el usuario
    //requiera entrar a una pagina en la cual este loggeado o para mostrar algo personalizado
    //por ejemplo, un home distinto para cada rol.
        if(!isset($_SESSION['usuario'])){
            return $this->error('No hay sesión iniciada');
        }
        else{
            return $this->respuestaGetSesion($_SESSION['usuario']);
        }
    }
    private function error(string $mensaje, int $codigo = 401){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
    private function respuestaInicioSesion(string $mensaje, array $datosUsuario, int $codigo =200){
        http_response_code($codigo);
        echo json_encode(['ok'=>$mensaje,
        'usuario'=>$datosUsuario]);
    }
    private function respuestaGetSesion(array $datosUsuario, int $codigo =200){
        http_response_code($codigo);
        echo json_encode(['usuario'=>$datosUsuario]);
    }
    private function respuestaSesionCerrada(string $mensaje, int $codigo=200){
        http_response_code($codigo);
        echo json_encode(['ok'=>$mensaje]);
    }
}