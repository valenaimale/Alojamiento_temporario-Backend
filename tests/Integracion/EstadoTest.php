<?php

namespace Tests\Integracion;

//RNF health check: GET /estado dice si el backend y la base de datos responden.
//(El caso de la base caída está en ErroresTest, que levanta un servidor sin base.)
final class EstadoTest extends CasoDeIntegracion
{
    public function testConLaBaseFuncionandoResponde200(): void
    {
        $respuesta = $this->cliente()->get('/estado');

        $this->assertSame(200, $respuesta->codigo);
        $datos = $respuesta->json();
        $this->assertSame('ok', $datos['estado']);
        $this->assertSame('ok', $datos['chequeos']['base_de_datos']);
        $this->assertSame('archivos', $datos['sesiones']);
        $this->assertArrayHasKey('version', $datos);
    }

    public function testCadaRespuestaTraeUnIdDePeticionDistinto(): void
    {
        $primera = $this->cliente()->get('/estado')->header('X-Request-Id');
        $segunda = $this->cliente()->get('/estado')->header('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $primera);
        $this->assertNotSame($primera, $segunda);
    }
}
