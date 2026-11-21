<?php

namespace App\Controllers;

use App\Request\Request;
use App\Request\Respuesta;
use App\Database\Conexion;
use App\Mail\VerificacionMail;
use App\Validacion\ValidadorDocumentos;
use App\Validacion\DatosFiscales;
use App\Validacion\RegistroPendiente;

//El registro con mail y contraseña. Desde el 19/11 se verifica el mail ANTES de crear la cuenta:
//POST /registrarse deja un registro pendiente y la cuenta se crea al abrir el enlace del mail (VerificacionMailController).
//Si el rol es propietario o administrador, al crearse la cuenta también se crea su fila en propietarios o en
//administradores, con los datos fiscales y bancarios que se validaron acá.
class RegistroController{
    //POST /registrarse: valida los datos, guarda el registro pendiente y manda el mail con el enlace que crea la cuenta
    public function registrar(){
        $datos_registro = Request::datosJson();//Request::datosJson decodifica el json a php (y controla su forma) de manera tal
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
        //Si el rol es propietario o administrador llegan ademas cobra_iva, cuit, razon_social, domicilio_fiscal,
        //cbu, alias, titular_cuenta y billetera.
        $nombre= trim($datos_registro['nombre'] ?? '');//trim elimina espacios en blanco al comienzo y al final del nombre
        $dni = trim($datos_registro['dni'] ?? '');
        $mail = strtolower(trim($datos_registro['mail'] ?? ''));//strtolower pasa el texto a todo minuscula
        $mailConfirmacion = strtolower(trim($datos_registro['mail_confirmacion'] ?? ''));
        $contrasenia = $datos_registro['contrasenia'] ?? '';//las contraseñas no se tocan: un espacio tambien es parte de la contraseña
        $contraseniaConfirmacion = $datos_registro['contrasenia_confirmacion'] ?? '';
        $rol = $datos_registro['rol'] ?? '';

        if($nombre==='' || $dni==='' || $mail === '' || $mailConfirmacion==='' || $contrasenia==='' || $contraseniaConfirmacion==='' || $rol===''){
            return Respuesta::error('Todos los campos son obligatorios');
        }
        //backoffice no está en la lista a propósito: esas cuentas no se registran desde la web
        if(!in_array($rol,['huesped', 'propietario','administrador','operador'], true)){
            return Respuesta::error('El rol no existe');
        }
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 70) {
            return Respuesta::error('El nombre debe tener entre 2 y 70 caracteres');
        }
        $dni = ValidadorDocumentos::normalizarDni($dni);//saca puntos, guiones y espacios: "30.123.456" pasa a "30123456"
        if(!ValidadorDocumentos::esDniValido($dni)){
            return Respuesta::error('El DNI tiene que tener 7 u 8 números');
        }
        if(!filter_var($mail, FILTER_VALIDATE_EMAIL)){
            return Respuesta::error('El mail no es válido');
        }
        if($mail !== $mailConfirmacion){
            return Respuesta::error('Los mails no coinciden');
        }
        if(strlen($contrasenia)> 72 || strlen($contrasenia)< 8){
            return Respuesta::error('La contraseña debe tener entre 8 y 72 caracteres');
        }
        if($contrasenia !== $contraseniaConfirmacion){
            return Respuesta::error('Las contraseñas no coinciden');
        }
        //los datos fiscales y bancarios solo se piden si se registra como propietario o administrador
        $fiscales = null;
        if($rol === 'propietario' || $rol === 'administrador'){
            $fiscales = DatosFiscales::validar($datos_registro);
            if(isset($fiscales['error'])){
                return Respuesta::error($fiscales['error']);
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
            return Respuesta::error('El mail ya está registrado');
        }
        $consulta2 = $pdo->prepare(
            'SELECT 1 FROM usuarios WHERE dni = ? LIMIT 1'
        );
        $consulta2->execute([$dni]);
        if($consulta2->fetchColumn()){
            return Respuesta::error('El DNI ya está registrado');
        }
        if($fiscales !== null && DatosFiscales::cuitRegistrado($fiscales['cuit'])){
            return Respuesta::error('El CUIT ya está registrado');
        }
        //Todavía NO se crea la cuenta: se guarda un registro pendiente y se manda el enlace por mail.
        //La cuenta se crea cuando alguien abre ese enlace (POST /verificar-mail), así un mail falso o ajeno nunca
        //ocupa un mail, un DNI o un CUIT. Si ese mail ya tenía un registro pendiente, este lo reemplaza.
        $token = RegistroPendiente::guardar([
            'nombre'         => $nombre,
            'mail'           => $mail,
            'dni'            => $dni,
            'contrasenia'    => $contrasenia,//se hashea adentro
            'rol'            => $rol,
            'datos_fiscales' => $fiscales,
        ]);
        VerificacionMail::enviarParaCrearCuenta($mail, $nombre, $token);//nunca lanza excepciones

        //202: el pedido se aceptó, pero la cuenta todavía no existe. No se inicia sesión.
        Respuesta::json([
            'ok'   => "Te enviamos un mail a $mail. Abrí el enlace para crear tu cuenta.",
            'mail' => $mail,
        ], 202);
    }

    //POST /reenviar-registro {"mail"}: manda un enlace nuevo para un registro pendiente (el de post-registro.html).
    //No requiere sesión: todavía no hay cuenta. Responde siempre lo mismo, exista o no un registro pendiente con
    //ese mail, para no revelar quién se está registrando. Como mucho un mail por minuto (RegistroPendiente::reenviar).
    public function reenviar(){
        $datos = Request::datosJson();
        $mail = strtolower(trim(is_string($datos['mail'] ?? null) ? $datos['mail'] : ''));
        if(!filter_var($mail, FILTER_VALIDATE_EMAIL)){
            return Respuesta::error('El mail no es válido');
        }

        $enlace = RegistroPendiente::reenviar($mail);
        if($enlace !== null){
            VerificacionMail::enviarParaCrearCuenta($mail, $enlace['nombre'], $enlace['token']);
        }

        Respuesta::json(['ok' => "Si hay un registro pendiente para $mail, te mandamos un enlace nuevo. Revisá también la carpeta de spam."]);
    }
}
