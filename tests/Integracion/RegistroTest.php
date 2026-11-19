<?php

namespace Tests\Integracion;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Soporte\Datos;

//POST /registrarse (RF-23) y el RNF de validación de datos en el backend.
//Desde el 19/11 el mail se verifica ANTES de crear la cuenta: registrarse deja un registro pendiente y la cuenta se crea
//al abrir el enlace del mail (POST /verificar-mail). El atajo registrar() de CasoDeIntegracion hace los dos pasos.
final class RegistroTest extends CasoDeIntegracion
{
    //Registrarse NO crea la cuenta ni inicia sesión: deja un registro pendiente, con la contraseña hasheada
    public function testRegistrarseNoCreaLaCuentaHastaAbrirElEnlace(): void
    {
        // Arrange
        $cliente = $this->cliente();
        $datos = Datos::registro('huesped');

        // Act
        $respuesta = $cliente->post('/registrarse', $datos);

        // Assert
        $this->assertSame(202, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame("Te enviamos un mail a {$datos['mail']}. Abrí el enlace para crear tu cuenta.", $respuesta->json()['ok']);
        $this->assertSame(401, $cliente->get('/sesion')->codigo, 'No inicia sesión: la cuenta todavía no existe');
        $this->assertSame(0, $this->contar('usuarios', $datos['mail']));
        $pendiente = $this->pendiente($datos['mail']);
        $this->assertTrue(password_verify($datos['contrasenia'], $pendiente['contrasenia']));
        $this->assertNull($pendiente['datos_fiscales']);
    }

    //Abrir el enlace crea la cuenta con el mail ya verificado, deja la sesión iniciada y borra el registro pendiente
    public function testAbrirElEnlaceCreaLaCuentaYIniciaLaSesion(): void
    {
        // Arrange
        $cliente = $this->cliente();
        $datos = Datos::registro('huesped');
        $cliente->post('/registrarse', $datos);
        $token = $this->tokenDelRegistro($datos['mail']);

        // Act
        $respuesta = $cliente->post('/verificar-mail', ['token' => $token]);

        // Assert
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Tu cuenta quedó creada', $respuesta->json()['ok']);
        $usuario = $respuesta->json()['usuario'];
        $this->assertSame('huesped', $usuario['rol']);
        $this->assertTrue($usuario['mail_verificado']);
        $this->assertArrayNotHasKey('contrasenia', $usuario);
        $this->assertSame($datos['mail'], $cliente->get('/sesion')->json()['usuario']['mail']);
        $this->assertNotNull($this->filaUsuario($datos['mail'])['mail_verificado_en']);
        $this->assertSame(0, $this->contar('registros_pendientes', $datos['mail']));
    }

    //Registrarse otra vez con el mismo mail reemplaza el pendiente: vale el último y el enlace anterior deja de servir
    public function testRegistrarseDeNuevoConElMismoMailReemplazaElPendiente(): void
    {
        // Arrange
        $primero = Datos::registro('huesped', ['nombre' => 'Primer Intento']);
        $this->cliente()->post('/registrarse', $primero);
        $tokenViejo = $this->tokenDelRegistro($primero['mail']);
        $segundo = Datos::registro('huesped', ['nombre' => 'Segundo Intento', 'mail' => $primero['mail'], 'mail_confirmacion' => $primero['mail']]);

        // Act
        $respuesta = $this->cliente()->post('/registrarse', $segundo);

        // Assert
        $this->assertSame(202, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(1, $this->contar('registros_pendientes', $primero['mail']));
        $this->assertSame('Segundo Intento', $this->pendiente($primero['mail'])['nombre']);
        $this->assertSame(422, $this->cliente()->post('/verificar-mail', ['token' => $tokenViejo])->codigo);
    }

    //Si mientras tanto otra cuenta ocupó el DNI, el enlace no crea nada, lo explica y borra el pendiente
    public function testSiOtraCuentaOcupoElDniElEnlaceNoCreaLaCuenta(): void
    {
        // Arrange
        $datos = Datos::registro('huesped');
        $this->cliente()->post('/registrarse', $datos);
        $token = $this->tokenDelRegistro($datos['mail']);
        $this->crearUsuarioEnBase('huesped', ['dni' => $datos['dni']]);

        // Act
        $respuesta = $this->cliente()->post('/verificar-mail', ['token' => $token]);

        // Assert
        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Ese DNI ya tiene una cuenta. Si es tuya, iniciá sesión.', $respuesta->json()['error']);
        $this->assertSame(0, $this->contar('usuarios', $datos['mail']));
        $this->assertSame(0, $this->contar('registros_pendientes', $datos['mail']));
    }

    //Un enlace de registro vencido no crea la cuenta
    public function testUnEnlaceDeRegistroVencidoNoCreaLaCuenta(): void
    {
        // Arrange
        $datos = Datos::registro('huesped');
        $this->cliente()->post('/registrarse', $datos);
        $token = $this->tokenDelRegistro($datos['mail'], '-1 HOUR');

        // Act
        $respuesta = $this->cliente()->post('/verificar-mail', ['token' => $token]);

        // Assert
        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame(0, $this->contar('usuarios', $datos['mail']));
    }

    //El reenvío responde siempre lo mismo (no revela quién se está registrando) y manda como mucho un mail por minuto
    public function testElReenvioNoRevelaNadaYEsperaUnMinuto(): void
    {
        // Arrange
        $datos = Datos::registro('huesped');
        $this->cliente()->post('/registrarse', $datos);
        $hashAntes = $this->pendiente($datos['mail'])['token_hash'];

        // Act
        $enseguida = $this->cliente()->post('/reenviar-registro', ['mail' => $datos['mail']]);
        $hashEnseguida = $this->pendiente($datos['mail'])['token_hash'];
        $this->pdo()->prepare('UPDATE registros_pendientes SET enviado_en = NOW() - INTERVAL 2 MINUTE WHERE mail = ?')->execute([$datos['mail']]);
        $despues = $this->cliente()->post('/reenviar-registro', ['mail' => $datos['mail']]);
        $sinRegistro = $this->cliente()->post('/reenviar-registro', ['mail' => Datos::mail()]);

        // Assert
        $this->assertSame(200, $enseguida->codigo, $enseguida->cuerpo);
        $this->assertSame($hashAntes, $hashEnseguida, 'Antes del minuto no genera otro enlace');
        $this->assertSame(200, $despues->codigo);
        $this->assertNotSame($hashAntes, $this->pendiente($datos['mail'])['token_hash'], 'Pasado el minuto, el enlace es nuevo');
        $this->assertSame(200, $sinRegistro->codigo);
        $this->assertStringStartsWith('Si hay un registro pendiente para', $sinRegistro->json()['ok']);
    }

    //Un propietario guarda en el pendiente sus datos fiscales y bancarios, y se pasan a su cuenta al abrir el enlace
    public function testLosDatosFiscalesEsperanEnElPendiente(): void
    {
        // Arrange
        $datos = Datos::registro('propietario');

        // Act
        $this->cliente()->post('/registrarse', $datos);

        // Assert
        $fiscales = json_decode($this->pendiente($datos['mail'])['datos_fiscales'], true);
        $this->assertSame($datos['cbu'], $fiscales['cbu']);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM propietarios p JOIN usuarios u ON u.id = p.usuario_id WHERE u.dni = ' . $this->pdo()->quote($datos['dni']))->fetchColumn());
    }

    //El registro pendiente de ese mail (el test falla si no existe)
    private function pendiente(string $mail): array
    {
        $consulta = $this->pdo()->prepare('SELECT * FROM registros_pendientes WHERE mail = ?');
        $consulta->execute([$mail]);
        $fila = $consulta->fetch();
        $this->assertNotFalse($fila, "No hay un registro pendiente para $mail");
        return $fila;
    }

    //Cuántas filas de usuarios o registros_pendientes tienen ese mail
    private function contar(string $tabla, string $mail): int
    {
        $consulta = $this->pdo()->prepare("SELECT COUNT(*) FROM $tabla WHERE mail = ?");
        $consulta->execute([$mail]);
        return (int) $consulta->fetchColumn();
    }

    //La contraseña se guarda hasheada con bcrypt, nunca en texto plano
    public function testLaContraseniaSeGuardaHasheada(): void
    {
        [, $datos] = $this->registrar();

        $fila = $this->filaUsuario($datos['mail']);
        $this->assertNotSame($datos['contrasenia'], $fila['contrasenia']);
        $this->assertStringStartsWith('$2y$', $fila['contrasenia']);//bcrypt
        $this->assertTrue(password_verify($datos['contrasenia'], $fila['contrasenia']));
    }

    //El mail se guarda en minúsculas y el DNI solo con números
    public function testElMailYElDniSeGuardanNormalizados(): void
    {
        $mail = Datos::mail();
        $dni = Datos::dni();
        $conFormato = substr($dni, 0, 2) . '.' . substr($dni, 2, 3) . '.' . substr($dni, 5);
        $this->registrar('huesped', [
            'mail'              => '  ' . strtoupper($mail) . ' ',
            'mail_confirmacion' => strtoupper($mail),
            'dni'               => $conFormato,
        ]);

        $fila = $this->filaUsuario($mail);
        $this->assertSame($dni, $fila['dni']);
    }

    //Un propietario con IVA queda con sus datos fiscales y su cuenta bancaria
    public function testUnPropietarioQueCobraIvaSeRegistraConSusDatosFiscalesYBancarios(): void
    {
        $fiscales = Datos::fiscalesConIva();
        [$cliente, $datos] = $this->registrar('propietario', $fiscales);

        $respuesta = $cliente->get('/datos-fiscales');
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'cobra_iva'        => true,
            'cuit'             => $fiscales['cuit'],
            'razon_social'     => $fiscales['razon_social'],
            'domicilio_fiscal' => $fiscales['domicilio_fiscal'],
            'cbu'              => $datos['cbu'],
            'alias'            => $datos['alias'],
            'titular_cuenta'   => $datos['titular_cuenta'],
            'billetera'        => $datos['billetera'],
        ], $respuesta->json()['datos_fiscales']);
    }

    //Épica 2: el administrador también tiene sus datos fiscales y bancarios, en la tabla administradores
    public function testUnAdministradorSeRegistraConSusDatosFiscalesYBancarios(): void
    {
        [$cliente, $datos, $usuario] = $this->registrar('administrador', ['alias' => '']);

        $respuesta = $cliente->get('/datos-fiscales');
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'cobra_iva'        => false,
            'cuit'             => null,
            'razon_social'     => null,
            'domicilio_fiscal' => null,
            'cbu'              => $datos['cbu'],
            'alias'            => null,
            'titular_cuenta'   => $datos['titular_cuenta'],
            'billetera'        => $datos['billetera'],
        ], $respuesta->json()['datos_fiscales']);

        $consulta = $this->pdo()->prepare('SELECT COUNT(*) FROM administradores WHERE usuario_id = ?');
        $consulta->execute([$usuario['id']]);
        $this->assertSame(1, (int) $consulta->fetchColumn());
    }

    //Un huésped no tiene datos fiscales: /datos-fiscales le responde 403
    public function testUnHuespedNoTieneDatosFiscales(): void
    {
        [$cliente] = $this->registrar('huesped');

        $respuesta = $cliente->get('/datos-fiscales');

        $this->assertSame(403, $respuesta->codigo);
    }

    //Una persona (un DNI) tiene una sola cuenta: el CUIT de un propietario no puede usarlo un administrador
    public function testNoSePuedeRepetirElCuitEntrePropietarioYAdministrador(): void
    {
        $fiscales = Datos::fiscalesConIva();
        $this->registrar('propietario', $fiscales);

        $respuesta = $this->cliente()->post('/registrarse', Datos::registro('administrador', $fiscales));

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El CUIT ya está registrado', $respuesta->json()['error']);
    }

    //No se puede registrar un mail que ya tiene cuenta
    public function testNoSePuedeRepetirElMail(): void
    {
        [, $datos] = $this->registrar();

        $otro = Datos::registro('huesped', ['mail' => $datos['mail'], 'mail_confirmacion' => $datos['mail']]);
        $respuesta = $this->cliente()->post('/registrarse', $otro);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El mail ya está registrado', $respuesta->json()['error']);
    }

    //No se puede registrar un DNI que ya tiene cuenta
    public function testNoSePuedeRepetirElDni(): void
    {
        [, $datos] = $this->registrar();

        $respuesta = $this->cliente()->post('/registrarse', Datos::registro('huesped', ['dni' => $datos['dni']]));

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El DNI ya está registrado', $respuesta->json()['error']);
    }

    //Cada caso manda un dato inválido directo a la API (salteando el front) y espera el rechazo.
    #[DataProvider('datosInvalidos')]
    public function testRechazaDatosInvalidos(array $cambios, string $error): void
    {
        $respuesta = $this->cliente()->post('/registrarse', Datos::registro($cambios['rol'] ?? 'huesped', $cambios));

        $this->assertSame(422, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($error, $respuesta->json()['error']);
    }

    //Cada caso: qué dato se cambia y qué error tiene que responder
    public static function datosInvalidos(): array
    {
        return [
            'falta el nombre'               => [['nombre' => ''], 'Todos los campos son obligatorios'],
            'nombre de una letra'           => [['nombre' => 'A'], 'El nombre debe tener entre 2 y 70 caracteres'],
            'nombre demasiado largo'        => [['nombre' => str_repeat('a', 71)], 'El nombre debe tener entre 2 y 70 caracteres'],
            'DNI corto'                     => [['dni' => '123'], 'El DNI tiene que tener 7 u 8 números'],
            'mail inválido'                 => [['mail' => 'no-es-un-mail', 'mail_confirmacion' => 'no-es-un-mail'], 'El mail no es válido'],
            'los mails no coinciden'        => [['mail_confirmacion' => 'otro@test.local'], 'Los mails no coinciden'],
            'contraseña corta'              => [['contrasenia' => '1234567', 'contrasenia_confirmacion' => '1234567'], 'La contraseña debe tener entre 8 y 72 caracteres'],
            'contraseña de más de 72'       => [['contrasenia' => str_repeat('a', 73), 'contrasenia_confirmacion' => str_repeat('a', 73)], 'La contraseña debe tener entre 8 y 72 caracteres'],
            'las contraseñas no coinciden'  => [['contrasenia_confirmacion' => 'otra-clave-1'], 'Las contraseñas no coinciden'],
            'rol inexistente'               => [['rol' => 'superadmin'], 'El rol no existe'],
            'backoffice no se registra'     => [['rol' => 'backoffice'], 'El rol no existe'],
            'propietario sin decir si cobra IVA' => [['rol' => 'propietario', 'cobra_iva' => ''], 'Indicá si cobrás IVA'],
            'CUIT con verificador incorrecto' => [['rol' => 'propietario', 'cobra_iva' => '1', 'cuit' => '20-30123456-9', 'razon_social' => 'X SRL', 'domicilio_fiscal' => 'Calle 123'], 'El CUIT no es válido'],
            'propietario sin CBU'           => [['rol' => 'propietario', 'cbu' => ''], 'El CBU tiene que tener 22 números'],
            'administrador sin decir si cobra IVA' => [['rol' => 'administrador', 'cobra_iva' => ''], 'Indicá si cobrás IVA'],
            'administrador con CBU inválido' => [['rol' => 'administrador', 'cbu' => '2850590940090418135202'], 'El CBU no es válido: revisá que esté bien escrito'],
            'administrador sin titular'     => [['rol' => 'administrador', 'titular_cuenta' => ''], 'El titular de la cuenta debe tener entre 2 y 150 caracteres'],
            'propietario sin billetera'     => [['rol' => 'propietario', 'billetera' => ''], 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
            'administrador con billetera inválida' => [['rol' => 'administrador', 'billetera' => '0x123'], 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
        ];
    }

    //Un campo con una lista u objeto en lugar de un texto se rechaza con 422, sin llegar a un error 500
    public function testRechazaCamposConElTipoEquivocado(): void
    {
        $respuesta = $this->cliente()->post('/registrarse', ['nombre' => ['Ana'], 'mail' => ['x' => 1]]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('Los datos enviados no son válidos', $respuesta->json()['error']);
    }

    //Un cuerpo que no es JSON válido se rechaza con 422
    public function testRechazaUnCuerpoQueNoEsJson(): void
    {
        $respuesta = $this->cliente()->post('/registrarse', '{"nombre":');

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('Los datos enviados no son válidos', $respuesta->json()['error']);
    }

    //Si el registro se rechaza, no queda nada guardado en la base: ni la cuenta ni un registro pendiente
    public function testUnRegistroRechazadoNoGuardaNada(): void
    {
        $datos = Datos::registro('huesped', ['contrasenia_confirmacion' => 'otra-clave-1']);
        $this->cliente()->post('/registrarse', $datos);

        $this->assertSame(0, $this->contar('usuarios', $datos['mail']));
        $this->assertSame(0, $this->contar('registros_pendientes', $datos['mail']));
    }
}
