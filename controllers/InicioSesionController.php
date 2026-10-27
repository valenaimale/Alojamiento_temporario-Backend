<?php

namespace App\Controllers;

use App\Request\Request;
use App\Database\Conexion;
use App\Autorizacion\SesionUsuario;
use App\Autorizacion\Autorizacion;

//Inicio de sesión con mail y contraseña, cierre de sesión y consulta de la sesión actual
//(rutas /iniciar-sesion, /cerrar-sesion y /sesion).
class InicioSesionController{
    //POST /iniciar-sesion: controla el mail y la contraseña y, si están bien, inicia la sesión
    public function iniciaSesion(){
        //se encarga del post de iniciar sesion
        $datos_inicio_sesion = Request::datosJson();
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
    //POST /cerrar-sesion: cierra la sesión del usuario
    public function cierraSesion(){
        SesionUsuario::cerrar();//vacía la sesión, borra la cookie y destruye la sesión del servidor
        return $this->respuestaSesionCerrada('Sesión cerrada');
    }
    //GET /sesion: devuelve el usuario logueado (el front la usa para saber quién es y qué puede ver)
    public function dameSesion(){//el front va a pegar contra esta url cuando el usuario
    //requiera entrar a una pagina en la cual este loggeado o para mostrar algo personalizado
    //por ejemplo, un home distinto para cada rol.
        Autorizacion::requiereSesion();//sin sesión (o con la cuenta deshabilitada) responde 401 y corta acá
        return $this->respuestaGetSesion($_SESSION['usuario']);
    }
    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 401){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
    //Responde el inicio de sesión exitoso, con los datos del usuario
    private function respuestaInicioSesion(string $mensaje, array $datosUsuario, int $codigo =200){
        http_response_code($codigo);
        echo json_encode(['ok'=>$mensaje,
        'usuario'=>$datosUsuario]);
    }
    //Responde los datos del usuario logueado
    private function respuestaGetSesion(array $datosUsuario, int $codigo =200){
        http_response_code($codigo);
        echo json_encode(['usuario'=>$datosUsuario]);
    }
    //Responde el cierre de sesión exitoso
    private function respuestaSesionCerrada(string $mensaje, int $codigo=200){
        http_response_code($codigo);
        echo json_encode(['ok'=>$mensaje]);
    }
}
