<?php

namespace Tests\Unitarios;

use App\Exceptions\DatosInvalidosException;
use App\Request\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//RNF de validación de datos: el cuerpo de las peticiones tiene que ser un objeto JSON con valores simples.
final class RequestTest extends TestCase
{
    public function testUnObjetoJsonSeDecodifica(): void
    {
        $this->assertSame(
            ['mail' => 'ana@test.local', 'activo' => true, 'id' => 9],
            Request::decodificarJson('{"mail":"ana@test.local","activo":true,"id":9}')
        );
    }

    public function testSinCuerpoDevuelveVacio(): void
    {
        $this->assertSame([], Request::decodificarJson(''));
        $this->assertSame([], Request::decodificarJson('   '));
        $this->assertSame([], Request::decodificarJson('{}'));
    }

    #[DataProvider('cuerposInvalidos')]
    public function testCuerposInvalidosLanzanDatosInvalidos(string $cuerpo): void
    {
        $this->expectException(DatosInvalidosException::class);
        Request::decodificarJson($cuerpo);
    }

    public static function cuerposInvalidos(): array
    {
        return [
            'JSON roto'                  => ['{"nombre":'],
            'un texto'                   => ['"hola"'],
            'un número'                  => ['42'],
            'una lista'                  => ['[1,2,3]'],
            'un campo con una lista'     => ['{"nombre":["Ana"]}'],
            'un campo con un objeto'     => ['{"mail":{"x":1}}'],
        ];
    }
}
