<?php

namespace App\Controllers;
use App\Database\Conexion;

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
        $consulta2 = $pdo->prepare(
        'INSERT INTO usuarios (nombre, mail, contrasenia, rol) VALUES (:nombre, :mail, :contrasenia, :rol)'
        );
        $consulta2->execute([
            'nombre'      => $nombre,
            'mail'        => $mail,
            'contrasenia' => $hashContra,
            'rol'         => $rol,
        ]);
        return $this->respuesta('Cuenta creada exitosamente');
    }    
    private function error(string $mensaje, int $codigo = 422){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
    private function respuesta(string $mensaje, int $codigo =201){
        http_response_code($codigo);
        echo json_encode(['ok'=>$mensaje]);
    }
}