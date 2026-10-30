<?php

namespace Tests\Integracion;

use GuzzleHttp\Client;

//Verificación de mail: el enlace es de un solo uso, vence a las 24 horas y el reenvío tiene un límite.
//
//La mayoría de los casos crean el token directo en la base (en la base se guarda su hash, como hace la app).
//El último recorre el camino completo con el mail real: necesita Mailpit (en GitHub Actions corre como servicio;
//en una máquina local, si Mailpit no está levantado, ese test se saltea).
final class VerificacionMailTest extends CasoDeIntegracion
{
    //Un enlace válido verifica el mail y la sesión pasa a tener mail_verificado en true
    public function testUnEnlaceValidoVerificaElMail(): void
    {
        [$cliente, , $usuario] = $this->registrar();
        $token = $this->crearToken($usuario['id']);

        $respuesta = $cliente->post('/verificar-mail', ['token' => $token]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Tu mail quedó verificado', $respuesta->json()['ok']);
        $this->assertTrue($cliente->get('/sesion')->json()['usuario']['mail_verificado']);
    }

    //El enlace abierto en otro navegador (o en la app de mail, sin la sesión): la sesión que ya estaba abierta
    //se entera en su próximo pedido, sin tener que volver a iniciar sesión, y ya puede reservar
    public function testVerificarEnOtroNavegadorActualizaLaSesionAbierta(): void
    {
        [$cliente, , $usuario] = $this->registrar();
        $this->assertFalse($cliente->get('/sesion')->json()['usuario']['mail_verificado']);

        $otroNavegador = $this->cliente();//sin la cookie de sesión
        $this->assertSame(200, $otroNavegador->post('/verificar-mail', ['token' => $this->crearToken($usuario['id'])])->codigo);

        $this->assertTrue($cliente->get('/sesion')->json()['usuario']['mail_verificado']);
        //reservar ya no responde "Tenés que verificar tu mail" (403): sin unidad válida, responde 404
        $this->assertSame(404, $cliente->post('/reservas/crear', [
            'unidad_id' => 999999, 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+12 days'),
            'personas' => 2, 'acepta_politica' => true,
        ])->codigo);
    }

    //Un enlace ya usado no sirve de nuevo
    public function testElEnlaceSeUsaUnaSolaVez(): void
    {
        [, , $usuario] = $this->registrar();
        $token = $this->crearToken($usuario['id']);

        $this->assertSame(200, $this->cliente()->post('/verificar-mail', ['token' => $token])->codigo);
        $segunda = $this->cliente()->post('/verificar-mail', ['token' => $token]);

        $this->assertSame(422, $segunda->codigo);
    }

    //Un enlace vencido no sirve
    public function testUnEnlaceVencidoNoSirve(): void
    {
        [, , $usuario] = $this->registrar();
        $token = $this->crearToken($usuario['id'], '-1 HOUR');

        $respuesta = $this->cliente()->post('/verificar-mail', ['token' => $token]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertStringContainsString('venció', $respuesta->json()['error']);
    }

    //Un token con un formato inválido se rechaza con 422, sin consultar la base
    public function testUnTokenConFormatoInvalido(): void
    {
        foreach (['', 'abc', str_repeat('z', 64), "' OR 1=1 -- "] as $token) {
            $respuesta = $this->cliente()->post('/verificar-mail', ['token' => $token]);
            $this->assertSame(422, $respuesta->codigo, "token: $token");
            $this->assertSame('El enlace no es válido', $respuesta->json()['error']);
        }
    }

    //No se puede pedir otro mail antes de un minuto (protege la casilla del usuario)
    public function testNoSePuedePedirOtroMailAntesDeUnMinuto(): void
    {
        //el registro ya generó un enlace: pedir otro enseguida está limitado (protege la casilla del usuario)
        [$cliente] = $this->registrar();

        $respuesta = $cliente->post('/reenviar-verificacion');

        $this->assertSame(429, $respuesta->codigo);
        $this->assertSame('Esperá un minuto antes de pedir otro mail', $respuesta->json()['error']);
    }

    //Con el mail ya verificado no se manda otro
    public function testUnMailYaVerificadoNoPideOtro(): void
    {
        [$cliente, , $usuario] = $this->registrar();
        $cliente->post('/verificar-mail', ['token' => $this->crearToken($usuario['id'])]);

        $this->assertSame(409, $cliente->post('/reenviar-verificacion')->codigo);
    }

    //Recorrido real: el mail llega a Mailpit, se abre su enlace y el mail queda verificado
    public function testRecorridoCompletoConElMailReal(): void
    {
        $mailpit = new Client(['base_uri' => getenv('MAILPIT_URL') ?: 'http://127.0.0.1:8025', 'timeout' => 5, 'http_errors' => false]);
        try {
            $mailpit->get('/api/v1/info');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Mailpit no está corriendo (en GitHub Actions sí corre este test)');
        }

        [$cliente, $datos] = $this->registrar();

        $busqueda = json_decode((string) $mailpit->get('/api/v1/search', ['query' => ['query' => 'to:"' . $datos['mail'] . '"']])->getBody(), true);
        $this->assertNotEmpty($busqueda['messages'] ?? [], 'No llegó el mail de verificación a Mailpit');
        $mensaje = json_decode((string) $mailpit->get('/api/v1/message/' . $busqueda['messages'][0]['ID'])->getBody(), true);
        $this->assertSame('Verificá tu mail en Alojamiento temporario', $mensaje['Subject']);
        $this->assertMatchesRegularExpression('/token=([0-9a-f]{64})/', $mensaje['Text']);
        preg_match('/token=([0-9a-f]{64})/', $mensaje['Text'], $coincidencia);

        $respuesta = $cliente->post('/verificar-mail', ['token' => $coincidencia[1]]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertTrue($cliente->get('/sesion')->json()['usuario']['mail_verificado']);
    }

    //Crea un enlace de verificación para el usuario y devuelve el token (en la base queda su hash)
    private function crearToken(int $usuarioId, string $vence = '+24 HOUR'): string
    {
        $token = bin2hex(random_bytes(32));
        [$signo, $cantidad, $unidad] = sscanf($vence, '%1s%d %s');
        $funcion = $signo === '-' ? 'DATE_SUB' : 'DATE_ADD';
        $this->pdo()->prepare(
            "INSERT INTO verificaciones_mail (usuario_id, token_hash, expira_en) VALUES (?, ?, $funcion(NOW(), INTERVAL $cantidad $unidad))"
        )->execute([$usuarioId, hash('sha256', $token)]);
        return $token;
    }
}
