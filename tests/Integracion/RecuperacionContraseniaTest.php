<?php

namespace Tests\Integracion;

use GuzzleHttp\Client;
use Tests\Soporte\Datos;

final class RecuperacionContraseniaTest extends CasoDeIntegracion
{
    protected function setUp(): void
    {
        // Base aislada de tests: todos los navegadores comparten la IP del servidor de prueba.
        $this->pdo()->exec('DELETE FROM limites_recuperacion');
    }

    public function testCambiaLaClaveRevocaSesionesYElTokenSeUsaUnaSolaVez(): void
    {
        foreach (['huesped', 'propietario', 'administrador', 'operador', 'backoffice'] as $rol) {
            $usuario = $this->crearUsuarioEnBase($rol);
            $primero = $this->iniciarSesion($usuario['mail']);
            $segundo = $this->iniciarSesion($usuario['mail']);
            $token = $this->crearToken($usuario['id']);
            $anonimo = $this->cliente();
            $respuesta = $anonimo->post('/restablecer-contrasenia', $this->cambio($token));
            $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
            $this->assertSame(401, $anonimo->get('/sesion')->codigo, 'No inicia sesión automáticamente');
            $this->assertSame(401, $primero->get('/sesion')->codigo);
            $this->assertSame(401, $segundo->get('/sesion')->codigo);
            $this->assertSame(401, $this->cliente()->post('/iniciar-sesion', ['mail' => $usuario['mail'], 'contrasenia' => Datos::CONTRASENIA])->codigo);
            $nuevaSesion = $this->iniciarSesion($usuario['mail'], 'NuevaClave2026!');
            $this->assertSame($rol, $nuevaSesion->get('/sesion')->json()['usuario']['rol']);
            $this->assertSame(422, $anonimo->post('/restablecer-contrasenia', $this->cambio($token))->codigo);
            $fila = $this->filaUsuario($usuario['mail']);
            $this->assertTrue(password_verify('NuevaClave2026!', $fila['contrasenia']));
            $this->assertSame(1, (int) $fila['version_sesion']);
            $this->assertNull($fila['mail_verificado_en'], 'Recuperar no debe cambiar la verificación ni los permisos');
        }
    }

    public function testRechazaVencidosDeshabilitadosYGoogleSinClave(): void
    {
        foreach (['vencido', 'deshabilitado', 'google'] as $caso) {
            $usuario = $this->crearUsuarioEnBase('huesped', $caso === 'google' ? ['contrasenia' => null] : []);
            $token = $this->crearToken($usuario['id'], $caso === 'vencido');
            if ($caso === 'deshabilitado') {
                $this->pdo()->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$usuario['id']]);
            }
            $respuesta = $this->cliente()->post('/restablecer-contrasenia', $this->cambio($token));
            $this->assertSame(422, $respuesta->codigo, $respuesta->cuerpo);
            $this->assertSame('enlace_invalido', $respuesta->json()['codigo']);
            $this->assertSame($usuario['contrasenia'], $this->filaUsuario($usuario['mail'])['contrasenia']);
        }
    }

    public function testValidaTiposFormatoLongitudYConfirmacionSinConsumirToken(): void
    {
        $usuario = $this->crearUsuarioEnBase('huesped');
        $token = $this->crearToken($usuario['id']);
        foreach ([
            ['token' => ''], ['token' => true], ['token' => ['a']], ['token' => str_repeat('z', 64)],
            ['token' => str_repeat('a', 64)], ['contrasenia' => 12345678],
            ['contrasenia' => 'corta'], ['contrasenia' => str_repeat('a', 73)],
            ['contrasenia' => str_repeat('á', 37)], ['confirmar_contrasenia' => 'distinta'],
        ] as $invalido) {
            $r = $this->cliente()->post('/restablecer-contrasenia', array_merge($this->cambio($token), $invalido));
            $this->assertSame(422, $r->codigo, $r->cuerpo);
        }
        $clave = str_repeat('a', 72);
        $this->assertSame(200, $this->cliente()->post('/restablecer-contrasenia', [
            'token' => $token, 'contrasenia' => $clave, 'confirmar_contrasenia' => $clave,
        ])->codigo);
        $this->assertSame(200, $this->iniciarSesion($usuario['mail'], $clave)->get('/sesion')->codigo);
    }

    public function testRespuestaPublicaIgualYLimitesNoBloqueanElLogin(): void
    {
        $usuario = $this->crearUsuarioEnBase('huesped');
        $google = $this->crearUsuarioEnBase('huesped', ['contrasenia' => null]);
        $inactivo = $this->crearUsuarioEnBase('huesped');
        $this->pdo()->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$inactivo['id']]);
        $cliente = $this->cliente();
        $primera = $cliente->post('/recuperar-contrasenia', ['mail' => strtoupper($usuario['mail'])]);
        $this->assertSame(200, $primera->codigo);
        // La siguiente petición espera que el servidor termine el envío posterior a la respuesta.
        $segunda = $cliente->post('/recuperar-contrasenia', ['mail' => $usuario['mail']]);
        $this->assertSame($primera->cuerpo, $segunda->cuerpo);
        $hash = $this->hashToken($usuario['id']);
        $this->assertIsString($hash);
        foreach ([$google['mail'], $inactivo['mail'], 'no-existe@prueba.test', $usuario['mail']] as $mail) {
            $r = $cliente->post('/recuperar-contrasenia', ['mail' => $mail]);
            $this->assertSame(200, $r->codigo);
            $this->assertSame($primera->cuerpo, $r->cuerpo);
        }
        $this->assertSame($hash, $this->hashToken($usuario['id']), 'El reenvío inmediato no invalida el enlace');
        $this->assertFalse($this->hashToken($google['id']));
        $this->assertFalse($this->hashToken($inactivo['id']));
        for ($i = 0; $i < 21; $i++) {
            $r = $cliente->post('/recuperar-contrasenia', ['mail' => 'limite-' . $i . '@prueba.test'], ['X-Forwarded-For' => '192.0.2.' . $i]);
            $this->assertSame($primera->cuerpo, $r->cuerpo);
        }
        $otro = $this->crearUsuarioEnBase('huesped');
        $cliente->post('/recuperar-contrasenia', ['mail' => $otro['mail']]);
        $cliente->get('/sesion');
        $this->assertFalse($this->hashToken($otro['id']), 'La IP tiene un límite aunque cambie X-Forwarded-For');
        $this->assertSame(200, $this->iniciarSesion($usuario['mail'])->get('/sesion')->codigo);
    }

    public function testSolicitudValidaElMail(): void
    {
        foreach (['', 'sin-arroba', 123, true, null, ['mail@prueba.test'], str_repeat('x', 255) . '@prueba.test'] as $mail) {
            $r = $this->cliente()->post('/recuperar-contrasenia', ['mail' => $mail]);
            $this->assertSame(422, $r->codigo, $r->cuerpo);
        }
    }

    public function testCorreoRealNuevoEnlaceRevocaAnteriorYAvisaElCambio(): void
    {
        $mailpit = new Client(['base_uri' => getenv('MAILPIT_URL') ?: 'http://127.0.0.1:8025', 'timeout' => 5, 'http_errors' => false]);
        try {
            $mailpit->get('/api/v1/info');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Mailpit no está corriendo');
        }
        $usuario = $this->crearUsuarioEnBase('huesped');
        $cliente = $this->cliente();
        $cliente->post('/recuperar-contrasenia', ['mail' => $usuario['mail']], ['Host' => 'sitio-falso.test']);
        $primero = $this->buscarCorreo($mailpit, $usuario['mail'], 'Recuperá tu cuenta de HayLugar');
        $this->assertStringContainsString('http://localhost:5500/vistas/sesion/restablecer-contrasenia.html#token=', $primero['Text']);
        preg_match('/#token=([0-9a-f]{64})/', $primero['Text'], $coincidencia);
        $tokenAnterior = $coincidencia[1];
        $this->assertSame(hash('sha256', $tokenAnterior), $this->hashToken($usuario['id']));
        $this->assertNotSame($tokenAnterior, $this->hashToken($usuario['id']));
        $this->pdo()->prepare('UPDATE limites_recuperacion SET inicio = UTC_TIMESTAMP() - INTERVAL 61 SECOND WHERE clave = ?')
            ->execute([hash('sha256', 'mail:' . $usuario['mail'])]);
        $cliente->post('/recuperar-contrasenia', ['mail' => $usuario['mail']]);
        $segundo = $this->buscarCorreo($mailpit, $usuario['mail'], 'Recuperá tu cuenta de HayLugar', $primero['ID']);
        preg_match('/#token=([0-9a-f]{64})/', $segundo['Text'], $coincidencia);
        $this->assertSame(422, $cliente->post('/restablecer-contrasenia', $this->cambio($tokenAnterior))->codigo);
        $this->assertSame(200, $cliente->post('/restablecer-contrasenia', $this->cambio($coincidencia[1]))->codigo);
        $aviso = $this->buscarCorreo($mailpit, $usuario['mail'], 'Tu contraseña de HayLugar cambió');
        $this->assertStringNotContainsString('NuevaClave2026!', $aviso['Text']);
        $this->assertStringNotContainsString($coincidencia[1], $aviso['Text']);
    }

    private function buscarCorreo(Client $mailpit, string $mail, string $asunto, ?string $excluir = null): array
    {
        for ($i = 0; $i < 30; $i++) {
            $resultados = json_decode((string) $mailpit->get('/api/v1/search', ['query' => ['query' => 'to:"' . $mail . '"']])->getBody(), true);
            foreach ($resultados['messages'] ?? [] as $mensaje) {
                if ($mensaje['Subject'] === $asunto && $mensaje['ID'] !== $excluir) {
                    return json_decode((string) $mailpit->get('/api/v1/message/' . $mensaje['ID'])->getBody(), true);
                }
            }
            usleep(100_000);
        }
        $this->fail('No llegó el correo esperado a Mailpit: ' . $asunto);
    }

    private function crearToken(int $usuarioId, bool $vencido = false): string
    {
        $token = bin2hex(random_bytes(32));
        $fecha = $vencido ? 'UTC_TIMESTAMP() - INTERVAL 1 SECOND' : 'UTC_TIMESTAMP() + INTERVAL 30 MINUTE';
        $this->pdo()->prepare("INSERT INTO recuperaciones_contrasenia (usuario_id, token_hash, expira_en, creado_en) VALUES (?, ?, $fecha, UTC_TIMESTAMP())")
            ->execute([$usuarioId, hash('sha256', $token)]);
        return $token;
    }

    private function hashToken(int $usuarioId): string|false
    {
        $consulta = $this->pdo()->prepare('SELECT token_hash FROM recuperaciones_contrasenia WHERE usuario_id = ?');
        $consulta->execute([$usuarioId]);
        return $consulta->fetchColumn();
    }

    private function cambio(string $token): array
    {
        return ['token' => $token, 'contrasenia' => 'NuevaClave2026!', 'confirmar_contrasenia' => 'NuevaClave2026!'];
    }
}
