<?php

namespace Tests\Unitarios;

use App\Log\Log;
use PHPUnit\Framework\TestCase;

//RNF de logging: cada evento es una línea JSON con campos fijos (fecha, nivel, mensaje, petición, usuario).
final class LogTest extends TestCase
{
    private array $serverOriginal;

    protected function setUp(): void
    {
        $this->serverOriginal = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverOriginal;
    }

    public function testElRegistroTieneLosCamposFijos(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/iniciar-sesion?algo=1';

        $registro = Log::armar('info', 'Algo pasó', null, ['usuario_id' => 7]);

        $this->assertSame('info', $registro['level']);
        $this->assertSame('Algo pasó', $registro['message']);
        $this->assertSame('POST', $registro['method']);
        $this->assertSame('/iniciar-sesion', $registro['path']);//sin la parte de ?algo=1
        $this->assertSame(['usuario_id' => 7], $registro['context']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $registro['time']));
        $this->assertArrayNotHasKey('exception', $registro);
    }

    public function testElIdDePeticionEsElMismoEnTodaLaPeticion(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', Log::idPeticion());
        $this->assertSame(Log::idPeticion(), Log::armar('error', 'x')['request_id']);
    }

    public function testConUnaExcepcionGuardaElDetalle(): void
    {
        $registro = Log::armar('error', 'Error no controlado', new \RuntimeException('se rompió algo'));

        $this->assertSame('error', $registro['level']);
        $this->assertSame(\RuntimeException::class, $registro['exception']);
        $this->assertSame('se rompió algo', $registro['error']);
        $this->assertSame(__FILE__, $registro['file']);
        $this->assertIsInt($registro['line']);
        $this->assertIsString($registro['trace']);
    }

    public function testElRegistroSePuedePasarAJson(): void
    {
        $linea = json_encode(Log::armar('warning', 'Acentos: ñandú'));
        $this->assertIsString($linea);
        $this->assertSame('Acentos: ñandú', json_decode($linea, true)['message']);
    }
}
