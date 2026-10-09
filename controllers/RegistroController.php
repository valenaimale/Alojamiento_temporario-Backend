<?php

namespace App\Controllers;
use App\Database\Conexion;
use App\Autorizacion\SesionUsuario;
use App\Mail\VerificacionMail;

//Si el rol es propietario, además de la fila en usuarios se crea su fila en propietarios (datos fiscales).
//La Tarea 1 lo completa con DNI, confirmación de mail y contraseña, y datos fiscales.
class RegistroController{
    public function registrar(){
        $datos_registro = json_decode(file_get_contents('php://input'), true);//se decodifica el json a php de manera tal
        //que la funcion de json_decode devuelve el json en formato de array asociativo, en este caso
        //que son los datos de registro, el array podria quedar asi, por ejemplo:
        //[
        //'nombre'      => 'Ana',
        //'mail'        => 'ana@mail.com',
        //'contrasenia' => '12345678',
        //'rol'         => 'huesped',
        //]
        $mail = strtolower(trim($datos_registro['mail'] ?? ''));//strtolower pasa el texto a todo minuscula
        $nombre= trim($datos_registro['nombre'] ?? '');//trim elimina espacios en blanco al comienzo y al final del nombre
        $contrasenia = $datos_registro['contrasenia'] ?? '';
        $rol = $datos_registro['rol'] ?? '';

        if($mail === '' || $nombre==='' || $contrasenia==='' || $rol===''){
            return $this->error('Todos los campos son obligatorios');
        }
        if(!filter_var($mail, FILTER_VALIDATE_EMAIL)){
            return $this->error('El mail es invalido');
        }
        if(strlen($contrasenia)> 72 || strlen($contrasenia)< 8){
            return $this->error('La contraseña debe tener entre 8 y 72 caracteres');
        }
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 70) {
            return $this->error('El nombre debe tener entre 2 y 70 caracteres');
        }
        //backoffice no está en la lista a propósito: esas cuentas no se registran desde la web
        if(!in_array($rol,['huesped', 'propietario','administrador','operador'], true)){
            return $this->error('El rol no existe');
        }


        $pdo = Conexion::obtener(); //la clase conexion nos devuelve el objeto pdo
        //que nos permite tirarle querys a la bd
        $consulta1 = $pdo->prepare(
            'SELECT 1 FROM usuarios WHERE mail = ? LIMIT 1'
        );
        $consulta1->execute([$mail]);
        $existe=$consulta1->fetchColumn();//devuelve false si no encontro ninguna fila
        if($existe){
            return $this->error('El mail ya esta registrado');
        }
        $hashContra = password_hash($contrasenia, PASSWORD_DEFAULT);

        //usuario + (si es propietario) su fila en propietarios van juntos: si falla uno, no queda guardado ninguno
        $pdo->beginTransaction();
        try {
            $consulta2 = $pdo->prepare(
            'INSERT INTO usuarios (nombre, mail, contrasenia, rol) VALUES (:nombre, :mail, :contrasenia, :rol)'
            );
            $consulta2->execute([
                'nombre'      => $nombre,
                'mail'        => $mail,
                'contrasenia' => $hashContra,
                'rol'         => $rol,
            ]);
            $idUsuario = (int) $pdo->lastInsertId();// el id que MySQL le asignó al usuario recién creado

            if($rol === 'propietario'){
                $cobraIva = ($datos_registro['cobra_iva'] ?? '0') === '1' ? 1 : 0;
                $consulta3 = $pdo->prepare('INSERT INTO propietarios (usuario_id, cobra_iva) VALUES (?, ?)');
                $consulta3->execute([$idUsuario, $cobraIva]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;//lo atrapa el router y responde 500
        }

        VerificacionMail::enviar($idUsuario);//Tarea 2: manda el mail de verificación (nunca lanza excepciones)
        $usuario = SesionUsuario::iniciar($idUsuario);//igual que en el login: arma la sesión y regenera el ID

        return $this->respuesta('Cuenta creada exitosamente', $usuario);
    }
    private function error(string $mensaje, int $codigo = 422){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
    private function respuesta(string $mensaje, array $datosUsuario, int $codigo =201){
        http_response_code($codigo);
        echo json_encode(['ok'=>$mensaje,
        'usuario'=>$datosUsuario]);
    }
}
