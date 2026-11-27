<?php

namespace Tests\Unitarios;

use App\Request\Request;
use PHPUnit\Framework\TestCase;

//Cómo se lee un número entero de lo que manda el front (Request::entero y Request::enteroDeLaUrl): la única forma de
//leer un id o una cantidad en los controladores
final class RequestEnteroTest extends TestCase
{
    //Lo que manda el front bien: un número o un texto con un número, dentro del rango
    public function testAceptaNumerosYTextosConNumeros(): void
    {
        $this->assertSame(5, Request::entero(5));
        $this->assertSame(5, Request::entero('5'));
        $this->assertSame(1, Request::entero(1));
        $this->assertSame(50, Request::entero(50, 1, 50));
        $this->assertSame(0, Request::entero('0', 0));
    }

    //Lo que no es un número entero válido: null, para que el controlador responda el error
    public function testRechazaLoQueNoEsUnEnteroValido(): void
    {
        $casos = [
            'true (filter_var lo tomaría como 1)' => true,
            'false'                               => false,
            'null'                                => null,
            'una lista'                           => [5],
            'un decimal'                          => 3.5,
            'texto con letras ("5abc")'           => '5abc',
            'texto vacío'                         => '',
            'cero (por defecto el mínimo es 1)'   => 0,
            'negativo'                            => -3,
        ];
        foreach ($casos as $caso => $valor) {
            $this->assertNull(Request::entero($valor), $caso);
        }
    }

    //Los bordes del rango: el mínimo y el máximo están incluidos
    public function testRespetaElRango(): void
    {
        $this->assertNull(Request::entero(51, 1, 50));
        $this->assertNull(Request::entero(0, 1, 50));
        $this->assertSame(1, Request::entero(1, 1, 50));
    }

    //Un parámetro de la URL: ?id=5 sirve; ?id=5abc, ?id=0, ?id[]=5 o sin id, no
    public function testLeeElParametroDeLaUrl(): void
    {
        $casos = [['5', 5], ['5abc', null], ['0', null], [['5'], null]];
        foreach ($casos as [$valor, $esperado]) {
            $_GET = ['id' => $valor];
            $this->assertSame($esperado, Request::enteroDeLaUrl('id'));
        }
        $_GET = [];
        $this->assertNull(Request::enteroDeLaUrl('id'));
    }
}
