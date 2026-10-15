<?php

namespace Tests\Integracion;

use Tests\Soporte\Datos;
use Tests\Soporte\EntornoDePrueba;

//Inicio de sesión con Google (OAuth 2.0). No se puede iniciar sesión de verdad con Google desde un test,
//pero sí se verifica todo lo que depende del backend: la redirección a Google, la protección con "state"
//contra CSRF, los errores y completar el DNI.
final class OAuthTest extends CasoDeIntegracion
{
    public function testRedirigeAGoogleConLosDatosCorrectos(): void
    {
        $respuesta = $this->cliente()->get('/oauth/google');

        $this->assertSame(302, $respuesta->codigo);
        $destino = (string) $respuesta->header('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $destino);
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $parametros);
        $this->assertSame(EntornoDePrueba::GOOGLE_CLIENT_ID, $parametros['client_id']);
        $this->assertSame(EntornoDePrueba::urlApi() . '/oauth/google/callback', $parametros['redirect_uri']);
        $this->assertSame('code', $parametros['response_type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $parametros['state']);
    }

    public function testSiElUsuarioCancelaEnGoogleVuelveAlLogin(): void
    {
        $respuesta = $this->cliente()->get('/oauth/google/callback', ['error' => 'access_denied']);

        $this->assertSame(302, $respuesta->codigo);
        $this->assertSame(EntornoDePrueba::URL_FRONT . '/vistas/sesion/inicio-sesion.html?error=cancelado', $respuesta->header('Location'));
    }

    public function testUnStateDistintoEsRechazado(): void
    {
        //protección contra CSRF: el callback solo se acepta con el mismo "state" que se generó en este navegador
        $cliente = $this->cliente();
        $cliente->get('/oauth/google');

        $respuesta = $cliente->get('/oauth/google/callback', ['state' => str_repeat('a', 32), 'code' => 'cualquiera']);

        $this->assertSame(302, $respuesta->codigo);
        $this->assertSame(EntornoDePrueba::URL_FRONT . '/vistas/sesion/inicio-sesion.html?error=invalido', $respuesta->header('Location'));
    }

    public function testUnCallbackSinHaberEmpezadoElLoginEsRechazado(): void
    {
        $respuesta = $this->cliente()->get('/oauth/google/callback', ['state' => str_repeat('a', 32), 'code' => 'cualquiera']);

        $this->assertStringEndsWith('?error=invalido', (string) $respuesta->header('Location'));
    }

    public function testCompletarElDniDeUnaCuentaSinDni(): void
    {
        //las cuentas creadas con Google no tienen DNI: se simula una así
        $usuario = $this->crearUsuarioEnBase('huesped', ['dni' => null]);
        $cliente = $this->iniciarSesion($usuario['mail']);
        $this->assertNull($cliente->get('/sesion')->json()['usuario']['dni']);

        $invalido = $cliente->post('/completar-datos', ['dni' => '12']);
        $this->assertSame(422, $invalido->codigo);

        $dni = Datos::dni();
        $respuesta = $cliente->post('/completar-datos', ['dni' => $dni]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($dni, $cliente->get('/sesion')->json()['usuario']['dni']);

        $otraVez = $cliente->post('/completar-datos', ['dni' => Datos::dni()]);
        $this->assertSame(409, $otraVez->codigo);
        $this->assertSame('Tu DNI ya está cargado', $otraVez->json()['error']);
    }

    public function testNoSePuedeCompletarConUnDniDeOtraPersona(): void
    {
        [, $otro] = $this->registrar();
        $usuario = $this->crearUsuarioEnBase('huesped', ['dni' => null]);
        $cliente = $this->iniciarSesion($usuario['mail']);

        $respuesta = $cliente->post('/completar-datos', ['dni' => $otro['dni']]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El DNI ya está registrado', $respuesta->json()['error']);
    }
}
