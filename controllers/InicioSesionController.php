<?php

namespace App\Controllers;
use App\Database\Conexion;
use App\Autorizacion\SesionUsuario;
use App\Autorizacion\Autorizacion;

class InicioSesionController{
    public function iniciaSesion(){
        //se encarga del post de iniciar sesion
        $datos_inicio_sesion = json_decode(file_get_contents('php://input'), true);
        $mail= strtolower(trim($datos_inicio_sesion['mail'] ?? ''));
        $contrasenia = $datos_inicio_sesion['contrasenia'] ?? '';
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT id, contrasenia, activo FROM usuarios WHERE mail = ? LIMIT 1'
        );
        $consulta->execute([$mail]);
        $fila=$consulta->fetch();//devuelve false si no encontro ninguna fila
        //contrasenia NULL = cuenta creada solo con Google: no puede entrar con contraseña.
        //Mismo mensaje en todos los casos, para no revelar qué mails tienen cuenta.
        if(!$fila || $fila['contrasenia'] === null || !password_verify($contrasenia, $fila['contrasenia'])){
            return $this->error('Mail o contraseña incorrectos');
        }
        if(!$fila['activo']){//la cuenta existe pero un administrador la deshabilito
            return $this->error('Tu cuenta está deshabilitada', 403);
        }
        //SesionUsuario arma $_SESSION['usuario'] (id, nombre, mail, dni, rol, mail_verificado) y regenera el ID de sesión
        $usuario = SesionUsuario::iniciar((int) $fila['id']);
        return $this->respuestaInicioSesion('Sesión iniciada', $usuario);
    }
    public function cierraSesion(){
        SesionUsuario::cerrar();//vacía la sesión, borra la cookie y destruye la sesión del servidor
        return $this->respuestaSesionCerrada('Sesión cerrada');
    }
    public function dameSesion(){//el front va a pegar contra esta url cuando el usuario
    //requiera entrar a una pagina en la cual este loggeado o para mostrar algo personalizado
    //por ejemplo, un home distinto para cada rol.
        Autorizacion::requiereSesion();//sin sesión (o con la cuenta deshabilitada) responde 401 y corta acá
        return $this->respuestaGetSesion($_SESSION['usuario']);
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
