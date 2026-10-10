<?php

namespace App\Controllers;
use App\Autorizacion\Autorizacion;
use App\Autorizacion\SesionUsuario;
use App\Database\Conexion;
use App\Mail\VerificacionMail;

//Verificación del mail del usuario (Tarea 2).
//El enlace que llega por mail trae el token en la URL; el front lo manda acá.
//El token es de un solo uso y vence a las 24 horas (lo genera App\Mail\VerificacionMail).
class VerificacionMailController{

    //POST /verificar-mail — NO requiere sesión: el enlace del mail se puede abrir en otro navegador
    public function verificar(){
        $datos = json_decode(file_get_contents('php://input'), true);
        $token = trim($datos['token'] ?? '');

        //el token son 64 caracteres hexadecimales (bin2hex de 32 bytes): si no tiene esa forma,
        //no hace falta ni consultar la base
        if($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)){
            return $this->error('El enlace no es válido');
        }

        $pdo = Conexion::obtener();
        //en la base está el hash, no el token: se busca por el hash de lo que llegó.
        //Las tres condiciones juntas dan el mismo mensaje: inexistente, ya usado o vencido.
        $consulta = $pdo->prepare(
            'SELECT id, usuario_id FROM verificaciones_mail
             WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW() LIMIT 1'
        );
        $consulta->execute([hash('sha256', $token)]);
        $fila = $consulta->fetch();//devuelve false si no encontro ninguna fila
        if(!$fila){
            return $this->error('El enlace no es válido o venció. Pedí uno nuevo desde el aviso de tu home.');
        }

        //marcar el mail como verificado y quemar el token van juntos: si falla uno, no queda guardado ninguno
        $pdo->beginTransaction();
        try {
            $consulta2 = $pdo->prepare('UPDATE usuarios SET mail_verificado_en = NOW() WHERE id = ?');
            $consulta2->execute([$fila['usuario_id']]);

            $consulta3 = $pdo->prepare('UPDATE verificaciones_mail SET usado_en = NOW() WHERE id = ?');
            $consulta3->execute([$fila['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;//lo atrapa el router y responde 500
        }

        //si el usuario tenía la sesión abierta en este navegador, su sesión todavía dice mail_verificado: false
        if(isset($_SESSION['usuario']) && (int) $_SESSION['usuario']['id'] === (int) $fila['usuario_id']){
            SesionUsuario::refrescar();
        }

        return $this->respuesta('Tu mail quedó verificado');
    }

    //POST /reenviar-verificacion — lo usa el aviso que ve el usuario logueado en su home
    public function reenviar(){
        Autorizacion::requiereSesion();//sin sesión (o con la cuenta deshabilitada) responde 401 y corta acá
        $usuario = $_SESSION['usuario'];//el id sale siempre de la sesión, nunca del cuerpo de la petición

        if($usuario['mail_verificado']){
            return $this->error('Tu mail ya está verificado', 409);
        }

        //para que no se pueda usar el reenvío para mandarle muchos mails a una casilla
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT 1 FROM verificaciones_mail
             WHERE usuario_id = ? AND creado_en > NOW() - INTERVAL 60 SECOND LIMIT 1'
        );
        $consulta->execute([$usuario['id']]);
        if($consulta->fetchColumn()){
            return $this->error('Esperá un minuto antes de pedir otro mail', 429);
        }

        VerificacionMail::enviar($usuario['id']);//nunca lanza excepciones
        return $this->respuesta('Te enviamos un nuevo mail de verificación a ' . $usuario['mail']);
    }

    private function error(string $mensaje, int $codigo = 422){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
    private function respuesta(string $mensaje, int $codigo = 200){
        http_response_code($codigo);
        echo json_encode(['ok' => $mensaje]);
    }
}
