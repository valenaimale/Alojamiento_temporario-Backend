<?php

namespace Tests\Unitarios;

use App\Validacion\ValidadorDocumentos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//RNF de validación de datos: el DNI y el CUIT se validan en el backend, sin confiar en el front.
final class ValidadorDocumentosTest extends TestCase
{
    //El DNI se normaliza sacando puntos, guiones y espacios
    public function testNormalizarDniSacaPuntosGuionesYEspacios(): void
    {
        $this->assertSame('30123456', ValidadorDocumentos::normalizarDni('30.123.456'));
        $this->assertSame('30123456', ValidadorDocumentos::normalizarDni('30 123-456'));
    }

    //Solo es válido un DNI de 7 u 8 números
    #[DataProvider('dnis')]
    public function testEsDniValido(string $dni, bool $esperado): void
    {
        $this->assertSame($esperado, ValidadorDocumentos::esDniValido($dni));
    }

    //Cada caso: un DNI y si es válido
    public static function dnis(): array
    {
        return [
            '8 dígitos'           => ['30123456', true],
            '7 dígitos'           => ['1234567', true],
            '6 dígitos'           => ['123456', false],
            '9 dígitos'           => ['123456789', false],
            'con letras'          => ['3012345A', false],
            'sin normalizar'      => ['30.123.456', false],
            'vacío'               => ['', false],
        ];
    }

    //El CUIT se normaliza sacando los guiones
    public function testNormalizarCuit(): void
    {
        $this->assertSame('20301234563', ValidadorDocumentos::normalizarCuit('20-30123456-3'));
    }

    //Solo es válido un CUIT de 11 números con su dígito verificador correcto
    #[DataProvider('cuits')]
    public function testEsCuitValido(string $cuit, bool $esperado): void
    {
        $this->assertSame($esperado, ValidadorDocumentos::esCuitValido($cuit));
    }

    //Cada caso: un CUIT y si es válido
    public static function cuits(): array
    {
        return [
            'válido'                                  => ['20301234563', true],
            'válido con dígito verificador 0'         => ['20300000070', true],
            'dígito verificador incorrecto'           => ['20301234569', false],
            'base cuyo verificador sería 10'          => ['20300000020', false],
            '10 dígitos'                              => ['2030123456', false],
            '12 dígitos'                              => ['203012345630', false],
            'con letras'                              => ['2030123456A', false],
            'sin normalizar'                          => ['20-30123456-3', false],
        ];
    }

    //El CBU se normaliza sacando espacios, puntos y guiones
    public function testNormalizarCbuSacaEspaciosPuntosYGuiones(): void
    {
        $this->assertSame('2850590940090418135201', ValidadorDocumentos::normalizarCbu('2850590 9 4009041813520-1'));
    }

    //Solo es válido un CBU de 22 números con sus dos dígitos verificadores correctos
    #[DataProvider('cbus')]
    public function testEsCbuValido(string $cbu, bool $esperado): void
    {
        $this->assertSame($esperado, ValidadorDocumentos::esCbuValido($cbu));
    }

    //Cada caso: un CBU y si es válido
    public static function cbus(): array
    {
        return [
            'válido'                                  => ['2850590940090418135201', true],
            'válido con los dos verificadores en 0'   => ['0110599500000000000000', true],
            'verificador del bloque 1 incorrecto'     => ['2850590840090418135201', false],
            'verificador del bloque 2 incorrecto'     => ['2850590940090418135202', false],
            '21 dígitos'                              => ['285059094009041813520', false],
            '23 dígitos'                              => ['28505909400904181352010', false],
            'con letras'                              => ['285059094009041813520A', false],
            'sin normalizar'                          => ['2850590 940090418135201', false],
        ];
    }

    //El alias se normaliza en minúsculas y sin los espacios de los bordes
    public function testNormalizarAliasPasaAMinusculasYSacaEspaciosDeLosBordes(): void
    {
        $this->assertSame('casa.playa.sol', ValidadorDocumentos::normalizarAlias('  Casa.Playa.Sol '));
    }

    //Solo es válido un alias de 6 a 20 caracteres: letras sin tilde ni ñ, números, puntos y guiones
    #[DataProvider('aliases')]
    public function testEsAliasValido(string $alias, bool $esperado): void
    {
        $this->assertSame($esperado, ValidadorDocumentos::esAliasValido($alias));
    }

    //Cada caso: un alias y si es válido
    public static function aliases(): array
    {
        return [
            'con puntos'                 => ['casa.playa.sol', true],
            'con guiones y números'      => ['cabanias-2026', true],
            '6 caracteres'               => ['abcdef', true],
            '20 caracteres'              => ['abcdefghij.klmnopqrs', true],
            '5 caracteres'               => ['abcde', false],
            '21 caracteres'              => ['abcdefghij.klmnopqrst', false],
            'con espacio'                => ['casa playa', false],
            'con ñ'                      => ['montaña.sol', false],
            'con tilde'                  => ['cabaña.mar', false],
            'con otro símbolo'           => ['casa_playa', false],
            'sin normalizar'             => ['Casa.Playa', false],
        ];
    }
}
