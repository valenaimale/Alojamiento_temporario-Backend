<?php

namespace App\Controllers;
use App\Database\Conexion;
use App\Config\Config;
use App\Autorizacion\Autorizacion;
use App\Autorizacion\SesionUsuario;
use App\Validacion\ValidadorDocumentos;
use League\OAuth2\Client\Provider\Google;

//Inicio de sesión y registro con Google (OAuth 2.0, flujo "Authorization Code").
//GET /oauth/google y GET /oauth/google/callback son navegaciones del navegador, no fetch:
//por eso responden con redirecciones (302) y no con JSON.
//Solo para cuentas huesped o propietario: el resto entra con mail y contraseña.
class OAuthController{
    //GET /oauth/google: arma la URL de Google, guarda el state en la sesión y redirige
    public function iniciarGoogle(){
        $proveedor = $this->proveedor();
        $url = $proveedor->getAuthorizationUrl([
            'scope'  => ['openid', 'email', 'profile'],
            'prompt' => 'select_account',          //siempre deja elegir la cuenta de Google
        ]);
        //el state es un valor aleatorio: Google lo devuelve en el callback y se compara con este (protección contra CSRF)
        $_SESSION['oauth_state'] = $proveedor->getState();
        $this->redirigir($url);
    }

    //GET /oauth/google/callback: Google vuelve acá con ?code=...&state=...
    //Busca o crea el usuario, inicia la sesión y redirige al front.
    //Todo va dentro del try: si algo falla, el usuario vuelve al login con un mensaje y no ve un JSON crudo.
    public function callbackGoogle(){
        try {
            if(isset($_GET['error'])){//el usuario canceló en la página de Google
                return $this->volverAlLogin('cancelado');
            }

            $stateRecibido = $_GET['state'] ?? '';
            $stateGuardado = $_SESSION['oauth_state'] ?? '';
            unset($_SESSION['oauth_state']);//el state sirve una sola vez
            if($stateRecibido === '' || $stateGuardado === '' || !hash_equals($stateGuardado, $stateRecibido)){
                return $this->volverAlLogin('invalido');
            }

            //se cambia el code por un token directamente con Google (servidor a servidor, con el client_secret)
            //y con el token se pide el perfil del usuario
            $proveedor = $this->proveedor();
            $token = $proveedor->getAccessToken('authorization_code', ['code' => $_GET['code'] ?? '']);
            $perfil = $proveedor->getResourceOwner($token);
            $sub = (string) $perfil->getId();
            $mail = strtolower(trim($perfil->getEmail() ?? ''));
            $nombre = trim($perfil->getName() ?? '');
            $mailVerificado = $perfil->getEmailVerified() === true;

            if(!$mailVerificado || $mail === ''){
                return $this->volverAlLogin('mail_no_verificado');
            }

            $usuarioId = $this->buscarPorIdentidad($sub);
            if($usuarioId === null){
                $usuario = $this->buscarPorMail($mail);
                if($usuario !== null){
                    //ya tiene cuenta con ese mail: se vincula con Google (solo huesped o propietario)
                    if(!in_array($usuario['rol'], ['huesped', 'propietario'], true)){
                        return $this->volverAlLogin('no_permitido');
                    }
                    $usuarioId = (int) $usuario['id'];
                    $this->vincular($usuarioId, $sub, $usuario['mail_verificado_en'] === null);
                }
                else{
                    $usuarioId = $this->crearCuenta($nombre, $mail, $sub);
                }
            }

            if(!$this->estaActivo($usuarioId)){//la cuenta existe pero un administrador la deshabilito
                return $this->volverAlLogin('deshabilitada');
            }

            SesionUsuario::iniciar($usuarioId);//arma la sesión y regenera el ID, igual que el login con contraseña
            $this->redirigir(Config::obtener('app.url_front') . '/vistas/sesion/oauth-retorno.html');
        } catch (\Throwable $e) {
            error_log($e);//el error real queda en la terminal del servidor
            $this->volverAlLogin('error');
        }
    }

    //POST /completar-datos: carga el DNI de las cuentas creadas con Google (o de usuarios viejos que no lo tienen)
    public function completarDatos(){
        Autorizacion::requiereSesion();//sin sesión responde 401 y corta acá

        if($_SESSION['usuario']['dni'] !== null){
            return $this->error('Tu DNI ya está cargado', 409);
        }

        $datos = json_decode(file_get_contents('php://input'), true);
        $dni = ValidadorDocumentos::normalizarDni(trim($datos['dni'] ?? ''));//"30.123.456" pasa a "30123456"
        if(!ValidadorDocumentos::esDniValido($dni)){
            return $this->error('El DNI tiene que tener 7 u 8 números');
        }

        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare('SELECT 1 FROM usuarios WHERE dni = ? LIMIT 1');
        $consulta->execute([$dni]);
        if($consulta->fetchColumn()){
            return $this->error('El DNI ya está registrado');
        }

        try {
            $consulta = $pdo->prepare('UPDATE usuarios SET dni = ? WHERE id = ?');
            $consulta->execute([$dni, $_SESSION['usuario']['id']]);//el id sale siempre de la sesion, nunca de lo que manda el front
        } catch (\PDOException $e) {
            //23000: otro usuario cargó el mismo DNI al mismo tiempo y los dos pasaron el control de arriba
            if($e->getCode() === '23000'){
                return $this->error('El DNI ya está registrado');
            }
            throw $e;//lo atrapa el router y responde 500
        }

        SesionUsuario::refrescar();//la sesión tenía dni null: se vuelve a cargar con el DNI nuevo
        http_response_code(200);
        echo json_encode(['ok' => 'Datos guardados', 'usuario' => $_SESSION['usuario']]);
    }

    //Devuelve el id del usuario vinculado a esa identidad de Google, o null si no hay ninguno
    private function buscarPorIdentidad(string $sub): ?int{
        $consulta = Conexion::obtener()->prepare(
            "SELECT usuario_id FROM identidades_oauth WHERE proveedor = 'google' AND sujeto = ?"
        );
        $consulta->execute([$sub]);
        $usuarioId = $consulta->fetchColumn();//devuelve false si no encontro ninguna fila
        return $usuarioId === false ? null : (int) $usuarioId;
    }

    //Devuelve ['id', 'rol', 'mail_verificado_en'] del usuario con ese mail, o null si no existe
    private function buscarPorMail(string $mail): ?array{
        $consulta = Conexion::obtener()->prepare(
            'SELECT id, rol, mail_verificado_en FROM usuarios WHERE mail = ?'
        );
        $consulta->execute([$mail]);
        $fila = $consulta->fetch();
        return $fila ?: null;
    }

    //Vincula una cuenta que ya existía con su identidad de Google.
    //Si el mail no estaba verificado, lo marca como verificado: Google ya lo verificó.
    private function vincular(int $usuarioId, string $sub, bool $verificarMail): void{
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->guardarIdentidad($usuarioId, $sub);
            if($verificarMail){
                $consulta = $pdo->prepare('UPDATE usuarios SET mail_verificado_en = NOW() WHERE id = ?');
                $consulta->execute([$usuarioId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    //Crea una cuenta de huésped sin contraseña ni DNI (el DNI se pide después en completar-datos)
    private function crearCuenta(string $nombre, string $mail, string $sub): int{
        if($nombre === ''){//si Google no manda el nombre, se usa la parte del mail antes de la @
            $nombre = explode('@', $mail)[0];
        }
        $nombre = mb_substr($nombre, 0, 70);

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $consulta = $pdo->prepare(
                "INSERT INTO usuarios (nombre, mail, contrasenia, rol, mail_verificado_en) VALUES (?, ?, NULL, 'huesped', NOW())"
            );
            $consulta->execute([$nombre, $mail]);
            $usuarioId = (int) $pdo->lastInsertId();
            $this->guardarIdentidad($usuarioId, $sub);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $usuarioId;
    }

    private function guardarIdentidad(int $usuarioId, string $sub): void{
        $consulta = Conexion::obtener()->prepare(
            "INSERT INTO identidades_oauth (usuario_id, proveedor, sujeto) VALUES (?, 'google', ?)"
        );
        $consulta->execute([$usuarioId, $sub]);
    }

    private function estaActivo(int $usuarioId): bool{
        $consulta = Conexion::obtener()->prepare('SELECT activo FROM usuarios WHERE id = ?');
        $consulta->execute([$usuarioId]);
        return (bool) $consulta->fetchColumn();
    }

    private function proveedor(): Google{
        return new Google([
            'clientId'     => Config::obtener('google.client_id'),
            'clientSecret' => Config::obtener('google.client_secret'),
            'redirectUri'  => Config::obtener('google.redirect_uri'),
        ]);
    }

    //Vuelve al login del front con un código de error que el front traduce a un mensaje
    private function volverAlLogin(string $codigo): void{
        $this->redirigir(Config::obtener('app.url_front') . '/vistas/sesion/inicio-sesion.html?error=' . $codigo);
    }

    //Aunque el bootstrap ya mandó Content-Type: application/json, la redirección funciona igual
    private function redirigir(string $url): void{
        http_response_code(302);
        header('Location: ' . $url);
    }

    private function error(string $mensaje, int $codigo = 422){
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
