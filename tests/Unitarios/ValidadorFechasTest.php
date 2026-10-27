<?php

namespace Tests\Unitarios;

use App\Validacion\ValidadorFechas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//Las fechas de temporadas, precios, bloqueos y búsquedas: "AAAA-MM-DD", fechas reales, y la cuenta de noches.
final class ValidadorFechasTest extends TestCase
{
    //Solo se aceptan fechas reales con el formato AAAA-MM-DD, y sin hora
    #[DataProvider('fechas')]
    public function testFecha(string $texto, bool $esValida): void
    {
        $fecha = ValidadorFechas::fecha($texto);

        $this->assertSame($esValida, $fecha !== null);
        if ($esValida) {
            $this->assertSame($texto, $fecha->format('Y-m-d'));
            $this->assertSame('00:00:00', $fecha->format('H:i:s'));//sin hora: las fechas son noches
        }
    }

    //Cada caso: un texto y si es una fecha válida
    public static function fechas(): array
    {
        return [
            'fecha válida'            => ['2026-10-05', true],
            '29 de febrero bisiesto'  => ['2028-02-29', true],
            '29 de febrero no bisiesto' => ['2027-02-29', false],
            '30 de febrero'           => ['2026-02-30', false],
            'mes 13'                  => ['2026-13-01', false],
            'formato dd/mm/aaaa'      => ['05/10/2026', false],
            'sin ceros a la izquierda' => ['2026-10-5', false],
            'con hora'                => ['2026-10-05 10:00', false],
            'vacío'                   => ['', false],
            'texto'                   => ['mañana', false],
        ];
    }

    //La fecha de hoy no tiene hora (las fechas son noches)
    public function testHoyNoTieneHora(): void
    {
        $this->assertSame('00:00:00', ValidadorFechas::hoy()->format('H:i:s'));
    }

    //La cuenta de noches no incluye el día de egreso
    public function testNochesNoCuentaElDiaDeEgreso(): void
    {
        //del 5/10 al 10/10: las noches del 5, 6, 7, 8 y 9
        $this->assertSame(5, ValidadorFechas::noches(ValidadorFechas::fecha('2026-10-05'), ValidadorFechas::fecha('2026-10-10')));
        //cruzando un cambio de mes
        $this->assertSame(3, ValidadorFechas::noches(ValidadorFechas::fecha('2026-10-30'), ValidadorFechas::fecha('2026-11-02')));
        //egreso igual o anterior al ingreso
        $this->assertSame(0, ValidadorFechas::noches(ValidadorFechas::fecha('2026-10-05'), ValidadorFechas::fecha('2026-10-05')));
        $this->assertSame(-2, ValidadorFechas::noches(ValidadorFechas::fecha('2026-10-05'), ValidadorFechas::fecha('2026-10-03')));
    }
}
