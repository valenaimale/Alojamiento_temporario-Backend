<?php

namespace App\Controllers;
use App\Database\Conexion;
use App\Autorizacion\Autorizacion;
use App\Autorizacion\SesionUsuario;
use App\Validacion\ValidadorDocumentos;

//Datos fiscales del propietario (tabla propietarios) y "hacerme propietario".
//Las tres funciones estaticas del final tambien las usa RegistroController, para no repetir
//las validaciones ni el INSERT cuando alguien se registra directamente como propietario.
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

        $datos = json_decode(file_get_contents('php://input'), true);
        $fiscales = self::validarDatosFiscales($datos ?? []);//si el cuerpo no es un JSON valido, json_decode devuelve null
        if(isset($fiscales['error'])){
            return $this->error($fiscales['error']);
        }
        if(self::cuitRegistrado($fiscales['cuit'])){
            return $this->error('El CUIT ya está registrado');
        }

        //el cambio de rol y la fila en propietarios van juntos: si falla uno, no queda guardado ninguno
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $consulta = $pdo->prepare("UPDATE usuarios SET rol = 'propietario' WHERE id = ?");
            $consulta->execute([$usuario['id']]);
            self::guardarDatosFiscales($usuario['id'], $fiscales);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;//lo atrapa el router y responde 500
        }

        SesionUsuario::refrescar();//la sesion tenia rol huesped: se vuelve a cargar para que tenga el rol nuevo
        http_response_code(200);
        echo json_encode(['ok' => 'Ya sos propietario', 'usuario' => $_SESSION['usuario']]);
    }

    //GET /datos-fiscales: los datos fiscales del propietario logueado, para mostrarlos en el perfil
    public function datosFiscales(){
        Autorizacion::requiere('propietario');
        $idUsuario = $_SESSION['usuario']['id'];

        $consulta = Conexion::obtener()->prepare(
            'SELECT cobra_iva, cuit, razon_social, domicilio_fiscal FROM propietarios WHERE usuario_id = ?'
        );
        $consulta->execute([$idUsuario]);
        $fila = $consulta->fetch();//devuelve false si no encontro ninguna fila
        if(!$fila){
            //no deberia pasar: todo usuario con rol propietario tiene su fila en propietarios
            throw new \RuntimeException("El propietario $idUsuario no tiene fila en propietarios");
        }

        http_response_code(200);
        echo json_encode(['datos_fiscales' => [
            'cobra_iva'        => (bool) $fila['cobra_iva'],//MySQL lo devuelve como 1 o 0
            'cuit'             => $fila['cuit'],
            'razon_social'     => $fila['razon_social'],
            'domicilio_fiscal' => $fila['domicilio_fiscal'],
        ]]);
    }

    //Valida los datos fiscales que llegan en el JSON y los deja listos para guardar.
    //Si algo esta mal devuelve ['error' => 'mensaje'].
    //Si esta bien devuelve ['cobra_iva' => 0 o 1, 'cuit' => ..., 'razon_social' => ..., 'domicilio_fiscal' => ...],
    //con los tres ultimos en null cuando no cobra IVA.
    public static function validarDatosFiscales(array $datos): array{
        $cobraIva = $datos['cobra_iva'] ?? '';
        if($cobraIva !== '0' && $cobraIva !== '1'){
            return ['error' => 'Indicá si cobrás IVA'];
        }
        if($cobraIva === '0'){//si no cobra IVA no se piden mas datos
            return ['cobra_iva' => 0, 'cuit' => null, 'razon_social' => null, 'domicilio_fiscal' => null];
        }

        $cuit = ValidadorDocumentos::normalizarCuit(trim($datos['cuit'] ?? ''));//"20-30123456-3" pasa a "20301234563"
        $razonSocial = trim($datos['razon_social'] ?? '');
        $domicilioFiscal = trim($datos['domicilio_fiscal'] ?? '');

        if(!ValidadorDocumentos::esCuitValido($cuit)){
            return ['error' => 'El CUIT no es válido'];
        }
        if(mb_strlen($razonSocial) < 2 || mb_strlen($razonSocial) > 150){
            return ['error' => 'La razón social debe tener entre 2 y 150 caracteres'];
        }
        if(mb_strlen($domicilioFiscal) < 5 || mb_strlen($domicilioFiscal) > 200){
            return ['error' => 'El domicilio fiscal debe tener entre 5 y 200 caracteres'];
        }
        return ['cobra_iva' => 1, 'cuit' => $cuit, 'razon_social' => $razonSocial, 'domicilio_fiscal' => $domicilioFiscal];
    }

    //Devuelve true si ya hay un propietario con ese CUIT
    public static function cuitRegistrado(?string $cuit): bool{
        if($cuit === null){//no cobra IVA: no hay CUIT para controlar
            return false;
        }
        $consulta = Conexion::obtener()->prepare('SELECT 1 FROM propietarios WHERE cuit = ? LIMIT 1');
        $consulta->execute([$cuit]);
        return (bool) $consulta->fetchColumn();//fetchColumn devuelve false si no encontro ninguna fila
    }

    //Crea la fila del usuario en propietarios, con lo que devolvio validarDatosFiscales.
    //Usa la misma conexion que quien la llama, asi que queda dentro de su transaccion.
    public static function guardarDatosFiscales(int $usuarioId, array $fiscales): void{
        $consulta = Conexion::obtener()->prepare(
            'INSERT INTO propietarios (usuario_id, cobra_iva, cuit, razon_social, domicilio_fiscal)
             VALUES (:usuario_id, :cobra_iva, :cuit, :razon_social, :domicilio_fiscal)'
        );
        $consulta->execute([
            'usuario_id'       => $usuarioId,
            'cobra_iva'        => $fiscales['cobra_iva'],
            'cuit'             => $fiscales['cuit'],
            'razon_social'     => $fiscales['razon_social'],
            'domicilio_fiscal' => $fiscales['domicilio_fiscal'],
        ]);
    }

    private function error(string $mensaje, int $codigo = 422){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
