<?php

namespace App\Controllers;

use App\Request\Request;
use App\Database\Conexion;
use App\Autorizacion\Autorizacion;
use App\Autorizacion\SesionUsuario;
use App\Validacion\DatosFiscales;

//"Hacerme propietario" y los datos fiscales y bancarios del usuario logueado.
//La validacion y el INSERT de esos datos estan en App\Validacion\DatosFiscales, porque tambien
//los usa RegistroController (registro de propietarios y de administradores).
class PropietarioController{
    //POST /hacerse-propietario: convierte al huesped logueado en propietario.
    //Le cambia el rol y le crea su fila en propietarios. Sigue siendo la misma cuenta.
    public function hacerse(){
        Autorizacion::requiere('huesped');//un propietario tambien pasa este control (hereda del huesped), por eso el if de abajo
        $usuario = $_SESSION['usuario'];//el id sale siempre de la sesion, nunca de lo que manda el front

        if($usuario['rol'] === 'propietario'){
            return $this->error('Ya sos propietario', 409);
        }
        if($usuario['dni'] === null){//pasa con las cuentas creadas con Google
            return $this->error('Completá tu DNI antes de hacerte propietario');
        }

        $datos = Request::datosJson();
        $fiscales = DatosFiscales::validar($datos);//los mismos datos que en el registro como propietario
        if(isset($fiscales['error'])){
            return $this->error($fiscales['error']);
        }
        if(DatosFiscales::cuitRegistrado($fiscales['cuit'])){
            return $this->error('El CUIT ya está registrado');
        }

        //el cambio de rol y la fila en propietarios van juntos: si falla uno, no queda guardado ninguno
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $consulta = $pdo->prepare("UPDATE usuarios SET rol = 'propietario' WHERE id = ?");
            $consulta->execute([$usuario['id']]);
            DatosFiscales::guardar('propietario', $usuario['id'], $fiscales);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;//lo atrapa el router y responde 500
        }

        SesionUsuario::refrescar();//la sesion tenia rol huesped: se vuelve a cargar para que tenga el rol nuevo
        http_response_code(200);
        echo json_encode(['ok' => 'Ya sos propietario', 'usuario' => $_SESSION['usuario']]);
    }

    //GET /datos-fiscales: los datos fiscales y bancarios del propietario o administrador logueado, para el perfil
    public function datosFiscales(){
        Autorizacion::requiereSesion();
        $usuario = $_SESSION['usuario'];//el id sale siempre de la sesion
        if($usuario['rol'] !== 'propietario' && $usuario['rol'] !== 'administrador'){
            return $this->error('No tenés permiso para hacer esto', 403);
        }

        $datos = DatosFiscales::buscar($usuario['rol'], $usuario['id']);
        if($datos === null){
            //no deberia pasar: todo propietario y todo administrador tiene su fila con los datos fiscales
            throw new \RuntimeException("El usuario {$usuario['id']} ({$usuario['rol']}) no tiene sus datos fiscales");
        }

        http_response_code(200);
        echo json_encode(['datos_fiscales' => $datos]);
    }

    private function error(string $mensaje, int $codigo = 422){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
