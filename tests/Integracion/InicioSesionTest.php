<?php

namespace Tests\Integracion;

use Tests\Soporte\Datos;

//POST /iniciar-sesion, GET /sesion y POST /cerrar-sesion (RF-23).
final class InicioSesionTest extends CasoDeIntegracion
{
    //Con el mail y la contraseña correctos, inicia la sesión y devuelve al usuario
    public function testIniciaSesionConMailYContrasenia(): void
    {
        [, $datos] = $this->registrar();
        $cliente = $this->cliente();

        $respuesta = $cliente->post('/iniciar-sesion', ['mail' => $datos['mail'], 'contrasenia' => $datos['contrasenia']]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Sesión iniciada', $respuesta->json()['ok']);
        $this->assertSame($datos['mail'], $cliente->get('/sesion')->json()['usuario']['mail']);
    }

    //El mail se compara en minúsculas y sin los espacios de los bordes
    public function testElMailNoDistingueMayusculasNiEspacios(): void
    {
        [, $datos] = $this->registrar();

        $respuesta = $this->cliente()->post('/iniciar-sesion', [
            'mail'        => ' ' . strtoupper($datos['mail']) . ' ',
            'contrasenia' => $datos['contrasenia'],
        ]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
    }

    //Contraseña mal y mail inexistente dan el mismo error: así no se puede averiguar qué mails tienen cuenta
    public function testContraseniaIncorrectaYMailInexistenteDanElMismoError(): void
    {
        [, $datos] = $this->registrar();

        $contraseniaMal = $this->cliente()->post('/iniciar-sesion', ['mail' => $datos['mail'], 'contrasenia' => 'otra-clave-1']);
        $mailInexistente = $this->cliente()->post('/iniciar-sesion', ['mail' => Datos::mail(), 'contrasenia' => 'otra-clave-1']);

        //mismo código y mismo mensaje: la respuesta no revela qué mails tienen cuenta
        $this->assertSame(401, $contraseniaMal->codigo);
        $this->assertSame(401, $mailInexistente->codigo);
        $this->assertSame('Mail o contraseña incorrectos', $contraseniaMal->json()['error']);
        $this->assertSame($contraseniaMal->json(), $mailInexistente->json());
    }

    //Los intentos clásicos de inyección SQL no inician sesión (las consultas son preparadas)
    public function testInyeccionSqlNoEntra(): void
    {
        $this->registrar();

        foreach (["' OR 1=1 -- ", "' OR '1'='1", "x@x.com' OR 1=1 #"] as $ataque) {
            $respuesta = $this->cliente()->post('/iniciar-sesion', ['mail' => $ataque, 'contrasenia' => $ataque]);
            $this->assertSame(401, $respuesta->codigo, "Entró con: $ataque");
        }
    }

    //Una cuenta deshabilitada por el backoffice no puede iniciar sesión
    public function testUnaCuentaDeshabilitadaNoPuedeEntrar(): void
    {
        [, $datos] = $this->registrar();
        $this->pdo()->prepare('UPDATE usuarios SET activo = 0 WHERE mail = ?')->execute([$datos['mail']]);

        $respuesta = $this->cliente()->post('/iniciar-sesion', ['mail' => $datos['mail'], 'contrasenia' => $datos['contrasenia']]);

        $this->assertSame(403, $respuesta->codigo);
        $this->assertSame('Tu cuenta está deshabilitada', $respuesta->json()['error']);
    }

    //Después de cerrar la sesión, /sesion responde 401
    public function testCerrarSesion(): void
    {
        [$cliente] = $this->registrar();

        $this->assertSame(200, $cliente->post('/cerrar-sesion')->codigo);

        $sesion = $cliente->get('/sesion');
        $this->assertSame(401, $sesion->codigo);
        $this->assertSame('No hay sesión iniciada', $sesion->json()['error']);
    }

    //Al iniciar sesión se genera un id de sesión nuevo (contra el robo de sesión por "session fixation")
    public function testAlIniciarSesionCambiaElIdDeSesion(): void
    {
        //protección contra "session fixation": el ID de sesión que el navegador tenía antes del login no sirve después
        [, $datos] = $this->registrar();
        $cliente = $this->cliente();
        $cliente->get('/estado');//crea una sesión anónima
        $antes = $cliente->cookieDeSesion();

        $cliente->post('/iniciar-sesion', ['mail' => $datos['mail'], 'contrasenia' => $datos['contrasenia']]);

        $this->assertNotNull($antes);
        $this->assertNotSame($antes, $cliente->cookieDeSesion());
    }

    //La cookie de sesión es HttpOnly y SameSite: JavaScript no la puede leer
    public function testLaCookieDeSesionNoSePuedeLeerDesdeJavascript(): void
    {
        [, $datos] = $this->registrar();

        $respuesta = $this->cliente()->post('/iniciar-sesion', ['mail' => $datos['mail'], 'contrasenia' => $datos['contrasenia']]);

        $cookie = implode(' ', $respuesta->headers('Set-Cookie'));
        $this->assertStringContainsString('PHPSESSID=', $cookie);
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
    }
}
