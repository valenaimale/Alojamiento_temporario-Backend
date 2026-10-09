<?php

namespace App\Controllers;
use App\Database\Conexion;
use App\Autorizacion\SesionUsuario;
use App\Mail\VerificacionMail;
use App\Validacion\ValidadorDocumentos;

//Si el rol es propietario, además de la fila en usuarios se crea su fila en propietarios (datos fiscales).
class RegistroController{
    public function registrar(){
        $datos_registro = json_decode(file_get_contents('php://input'), true);//se decodifica el json a php de manera tal
        //que la funcion de json_decode devuelve el json en formato de array asociativo, en este caso
        //que son los datos de registro, el array podria quedar asi, por ejemplo:
        //[
        //'nombre'                   => 'Ana',
        //'dni'                      => '30.123.456',
        //'mail'                     => 'ana@mail.com',
        //'mail_confirmacion'        => 'ana@mail.com',
        //'contrasenia'              => '12345678',
        //'contrasenia_confirmacion' => '12345678',
        //'rol'                      => 'huesped',
        //]
        //Si el rol es propietario llegan ademas cobra_iva, cuit, razon_social y domicilio_fiscal.
        $nombre= trim($datos_registro['nombre'] ?? '');//trim elimina espacios en blanco al comienzo y al final del nombre
        $dni = trim($datos_registro['dni'] ?? '');
        $mail = strtolower(trim($datos_registro['mail'] ?? ''));//strtolower pasa el texto a todo minuscula
        $mailConfirmacion = strtolower(trim($datos_registro['mail_confirmacion'] ?? ''));
        $contrasenia = $datos_registro['contrasenia'] ?? '';//las contraseñas no se tocan: un espacio tambien es parte de la contraseña
        $contraseniaConfirmacion = $datos_registro['contrasenia_confirmacion'] ?? '';
        $rol = $datos_registro['rol'] ?? '';

        if($nombre==='' || $dni==='' || $mail === '' || $mailConfirmacion==='' || $contrasenia==='' || $contraseniaConfirmacion==='' || $rol===''){
            return $this->error('Todos los campos son obligatorios');
        }
        //backoffice no está en la lista a propósito: esas cuentas no se registran desde la web
        if(!in_array($rol,['huesped', 'propietario','administrador','operador'], true)){
            return $this->error('El rol no existe');
        }
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 70) {
            return $this->error('El nombre debe tener entre 2 y 70 caracteres');
        }
        $dni = ValidadorDocumentos::normalizarDni($dni);//saca puntos, guiones y espacios: "30.123.456" pasa a "30123456"
        if(!ValidadorDocumentos::esDniValido($dni)){
            return $this->error('El DNI tiene que tener 7 u 8 números');
        }
        if(!filter_var($mail, FILTER_VALIDATE_EMAIL)){
            return $this->error('El mail no es válido');
        }
        if($mail !== $mailConfirmacion){
            return $this->error('Los mails no coinciden');
        }
        if(strlen($contrasenia)> 72 || strlen($contrasenia)< 8){
            return $this->error('La contraseña debe tener entre 8 y 72 caracteres');
        }
        if($contrasenia !== $contraseniaConfirmacion){
            return $this->error('Las contraseñas no coinciden');
        }
        //los datos fiscales solo se piden si se registra como propietario
        $fiscales = null;
        if($rol === 'propietario'){
            $fiscales = PropietarioController::validarDatosFiscales($datos_registro);
            if(isset($fiscales['error'])){
                return $this->error($fiscales['error']);
            }
        }

        $pdo = Conexion::obtener(); //la clase conexion nos devuelve el objeto pdo
        //que nos permite tirarle querys a la bd
        $consulta1 = $pdo->prepare(
            'SELECT 1 FROM usuarios WHERE mail = ? LIMIT 1'
        );
        $consulta1->execute([$mail]);
        $existe=$consulta1->fetchColumn();//devuelve false si no encontro ninguna fila
        if($existe){
            return $this->error('El mail ya está registrado');
        }
        $consulta2 = $pdo->prepare(
            'SELECT 1 FROM usuarios WHERE dni = ? LIMIT 1'
        );
        $consulta2->execute([$dni]);
        if($consulta2->fetchColumn()){
            return $this->error('El DNI ya está registrado');
        }
        if($fiscales !== null && PropietarioController::cuitRegistrado($fiscales['cuit'])){
            return $this->error('El CUIT ya está registrado');
        }
        $hashContra = password_hash($contrasenia, PASSWORD_DEFAULT);

        //usuario + (si es propietario) su fila en propietarios van juntos: si falla uno, no queda guardado ninguno
        $pdo->beginTransaction();
        try {
            $consulta3 = $pdo->prepare(
            'INSERT INTO usuarios (nombre, mail, dni, contrasenia, rol) VALUES (:nombre, :mail, :dni, :contrasenia, :rol)'
            );
            $consulta3->execute([
                'nombre'      => $nombre,
                'mail'        => $mail,
                'dni'         => $dni,
                'contrasenia' => $hashContra,
                'rol'         => $rol,
            ]);
            $idUsuario = (int) $pdo->lastInsertId();// el id que MySQL le asignó al usuario recién creado

            if($fiscales !== null){
                PropietarioController::guardarDatosFiscales($idUsuario, $fiscales);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            //23000 es el codigo de "valor repetido en un indice unico": pasa si dos personas se registran
            //al mismo tiempo con el mismo mail, DNI o CUIT y las dos pasaron los controles de arriba
            if($e instanceof \PDOException && $e->getCode() === '23000'){
                return $this->error('El mail, el DNI o el CUIT ya están registrados');
            }
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
