<?php

namespace Tests\Unitarios;

use App\Exceptions\DatosInvalidosException;
use App\Request\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//RNF de validación de datos: el cuerpo de las peticiones tiene que ser un objeto JSON con valores simples.
final class RequestTest extends TestCase
{
    //Un objeto JSON se convierte en un array asociativo
    public function testUnObjetoJsonSeDecodifica(): void
    {
        $this->assertSame(
            ['mail' => 'ana@test.local', 'activo' => true, 'id' => 9],
            Request::decodificarJson('{"mail":"ana@test.local","activo":true,"id":9}')
        );
    }

    //Sin cuerpo (o solo espacios) devuelve un array vacío
    public function testSinCuerpoDevuelveVacio(): void
    {
        $this->assertSame([], Request::decodificarJson(''));
        $this->assertSame([], Request::decodificarJson('   '));
        $this->assertSame([], Request::decodificarJson('{}'));
    }

    public function testListaSoloEnCampoHabilitado(): void
    {
        $this->assertSame(['huespedes' => [['nombre' => 'Ana']]], Request::decodificarJson('{"huespedes":[{"nombre":"Ana"}]}', ['huespedes']));
        $this->expectException(DatosInvalidosException::class);
        Request::decodificarJson('{"huespedes":[],"nombre":["Ana"]}', ['huespedes']);
    }

    //Cada cuerpo inválido lanza DatosInvalidosException (el router responde 422)
    #[DataProvider('cuerposInvalidos')]
    public function testCuerposInvalidosLanzanDatosInvalidos(string $cuerpo): void
    {
        $this->expectException(DatosInvalidosException::class);
        Request::decodificarJson($cuerpo);
    }

    //Cada caso: un cuerpo que no tiene la forma esperada
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
