<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;
use Tests\Soporte\EntornoDePrueba;

//RNF de manejo de errores, logging y health check, con la base de datos CAÍDA.
//Para eso levanta un segundo backend apuntando a un puerto donde no hay ninguna base (DB_PORT=1).
//De paso lo levanta con la cookie segura activada, como en producción, para verificar ese header.
final class ErroresTest extends CasoDeIntegracion
{
    private static int $puerto;
    private static string $log;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$puerto = EntornoDePrueba::puerto() + 1;
        self::$log = EntornoDePrueba::levantarServidor(self::$puerto, [
            'DB_HOST'                => '127.0.0.1',
            'DB_PORT'                => '1',
            'SESIONES_COOKIE_SEGURA' => '1',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        EntornoDePrueba::detenerServidor(self::$puerto);
    }

    private function sinBase(): ClienteApi
    {
        return new ClienteApi(EntornoDePrueba::urlApi(self::$puerto));
    }

    public function testElHealthCheckResponde503SiLaBaseNoResponde(): void
    {
        $respuesta = $this->sinBase()->get('/estado');

        $this->assertSame(503, $respuesta->codigo);
        $this->assertSame('error', $respuesta->json()['estado']);
        $this->assertSame('error', $respuesta->json()['chequeos']['base_de_datos']);
    }

    public function testElUsuarioRecibeUnMensajeGenericoSinDetallesInternos(): void
    {
        $respuesta = $this->sinBase()->post('/iniciar-sesion', ['mail' => 'ana@test.local', 'contrasenia' => 'clave-segura-1']);

        $this->assertSame(500, $respuesta->codigo);
        $this->assertSame(
            ['error' => 'Estamos con algunos inconvenientes. Vuelva a intentar en unos instantes...'],
            $respuesta->json()
        );
        foreach (['SQLSTATE', 'PDOException', '.php', 'Stack trace', '#0'] as $detalleInterno) {
            $this->assertStringNotContainsString($detalleInterno, $respuesta->cuerpo);
        }
    }

    public function testElErrorQuedaEnElLogEnFormatoJson(): void
    {
        $respuesta = $this->sinBase()->post('/iniciar-sesion', ['mail' => 'ana@test.local', 'contrasenia' => 'clave-segura-1']);
        $idPeticion = $respuesta->header('X-Request-Id');
        usleep(200_000);//el servidor escribe el log apenas responde

        $registro = $this->buscarEnLog($idPeticion);

        $this->assertNotNull($registro, "No está en el log la petición $idPeticion");
        $this->assertSame('error', $registro['level']);
        $this->assertSame('Error no controlado', $registro['message']);
        $this->assertSame('POST', $registro['method']);
        $this->assertSame('/iniciar-sesion', $registro['path']);
        $this->assertSame('PDOException', $registro['exception']);
        $this->assertStringContainsString('SQLSTATE', $registro['error']);//el detalle real sí está en el log
        $this->assertStringContainsString('Conexion.php', $registro['file']);
        $this->assertNotEmpty($registro['trace']);
    }

    public function testConCookieSeguraLaCookieSoloViajaPorHttps(): void
    {
        $respuesta = $this->sinBase()->get('/estado');

        $cookie = implode(' ', $respuesta->headers('Set-Cookie'));
        $this->assertStringContainsString('PHPSESSID=', $cookie);
        $this->assertStringContainsStringIgnoringCase('secure', $cookie);
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
    }

    public function testNingunaRespuestaInformaLaVersionDePhp(): void
    {
        foreach ([$this->cliente()->get('/estado'), $this->cliente()->get('/no-existe'), $this->sinBase()->get('/estado')] as $respuesta) {
            $this->assertNull($respuesta->header('X-Powered-By'));
        }
    }

    public function testUnaRutaInexistenteResponde404EnJson(): void
    {
        $respuesta = $this->cliente()->get('/no-existe');

        $this->assertSame(404, $respuesta->codigo);
        $this->assertSame(['error' => 'Ruta no encontrada'], $respuesta->json());
        $this->assertStringStartsWith('application/json', (string) $respuesta->header('Content-Type'));
    }

    public function testCorsSoloAutorizaAlFront(): void
    {
        $permitido = $this->cliente()->options('/iniciar-sesion', ['Origin' => EntornoDePrueba::URL_FRONT, 'Access-Control-Request-Method' => 'POST']);
        $ajeno = $this->cliente()->options('/iniciar-sesion', ['Origin' => 'https://sitio-malicioso.com', 'Access-Control-Request-Method' => 'POST']);

        $this->assertSame(204, $permitido->codigo);
        $this->assertSame(EntornoDePrueba::URL_FRONT, $permitido->header('Access-Control-Allow-Origin'));
        $this->assertNull($ajeno->header('Access-Control-Allow-Origin'));
    }

    //Busca en el log del servidor la línea JSON de esa petición
    private function buscarEnLog(?string $idPeticion): ?array
    {
        foreach (file(self::$log, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
            $registro = json_decode($linea, true);
            if (is_array($registro) && ($registro['request_id'] ?? null) === $idPeticion) {
                return $registro;
            }
        }
        return null;
    }
}
