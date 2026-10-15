<?php

namespace Tests\Integracion;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Soporte\Datos;

//POST /registrarse (RF-23) y el RNF de validación de datos en el backend.
final class RegistroTest extends CasoDeIntegracion
{
    public function testUnHuespedSeRegistraYQuedaConLaSesionIniciada(): void
    {
        $cliente = $this->cliente();
        $datos = Datos::registro('huesped');

        $respuesta = $cliente->post('/registrarse', $datos);

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Cuenta creada exitosamente', $respuesta->json()['ok']);
        $usuario = $respuesta->json()['usuario'];
        $this->assertSame('huesped', $usuario['rol']);
        $this->assertFalse($usuario['mail_verificado']);
        $this->assertArrayNotHasKey('contrasenia', $usuario);

        //la sesión quedó iniciada: GET /sesion devuelve al usuario
        $sesion = $cliente->get('/sesion');
        $this->assertSame(200, $sesion->codigo);
        $this->assertSame($datos['mail'], $sesion->json()['usuario']['mail']);
    }

    public function testLaContraseniaSeGuardaHasheada(): void
    {
        [, $datos] = $this->registrar();

        $fila = $this->filaUsuario($datos['mail']);
        $this->assertNotSame($datos['contrasenia'], $fila['contrasenia']);
        $this->assertStringStartsWith('$2y$', $fila['contrasenia']);//bcrypt
        $this->assertTrue(password_verify($datos['contrasenia'], $fila['contrasenia']));
    }

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

    public function testUnPropietarioQueCobraIvaSeRegistraConSusDatosFiscales(): void
    {
        $fiscales = Datos::fiscalesConIva();
        [$cliente] = $this->registrar('propietario', $fiscales);

        $respuesta = $cliente->get('/datos-fiscales');
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'cobra_iva'        => true,
            'cuit'             => $fiscales['cuit'],
            'razon_social'     => $fiscales['razon_social'],
            'domicilio_fiscal' => $fiscales['domicilio_fiscal'],
        ], $respuesta->json()['datos_fiscales']);
    }

    public function testNoSePuedeRepetirElMail(): void
    {
        [, $datos] = $this->registrar();

        $otro = Datos::registro('huesped', ['mail' => $datos['mail'], 'mail_confirmacion' => $datos['mail']]);
        $respuesta = $this->cliente()->post('/registrarse', $otro);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El mail ya está registrado', $respuesta->json()['error']);
    }

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
        ];
    }

    public function testRechazaCamposConElTipoEquivocado(): void
    {
        $respuesta = $this->cliente()->post('/registrarse', ['nombre' => ['Ana'], 'mail' => ['x' => 1]]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('Los datos enviados no son válidos', $respuesta->json()['error']);
    }

    public function testRechazaUnCuerpoQueNoEsJson(): void
    {
        $respuesta = $this->cliente()->post('/registrarse', '{"nombre":');

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('Los datos enviados no son válidos', $respuesta->json()['error']);
    }

    public function testUnRegistroRechazadoNoGuardaNada(): void
    {
        $datos = Datos::registro('huesped', ['contrasenia_confirmacion' => 'otra-clave-1']);
        $this->cliente()->post('/registrarse', $datos);

        $consulta = $this->pdo()->prepare('SELECT COUNT(*) FROM usuarios WHERE mail = ?');
        $consulta->execute([$datos['mail']]);
        $this->assertSame(0, (int) $consulta->fetchColumn());
    }
}
