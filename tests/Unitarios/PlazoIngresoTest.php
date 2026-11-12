<?php

namespace Tests\Unitarios;

use App\Validacion\Estadia;
use App\Validacion\PlazoIngreso;
use PHPUnit\Framework\TestCase;

//El plazo para registrar el ingreso (no-show, decisión #67) y el redondeo del descuento por estadía larga (#69).
final class PlazoIngresoTest extends TestCase
{
    //24 hs desde el check-in del día del ingreso, en hora de Argentina (UTC−3), pasadas a UTC
    public function testElLimiteEsVeinticuatroHorasDespuesDelCheckIn(): void
    {
        $this->assertSame('2026-12-28 17:00:00', PlazoIngreso::limite('2026-12-27', '14:00:00'));
        $this->assertSame('2026-12-28 17:00:00', PlazoIngreso::limite('2026-12-27', '14:00'));
        $this->assertSame('2027-01-01 02:30:00', PlazoIngreso::limite('2026-12-30', '23:30'));//cambia de año en UTC
        $this->assertSame('2026-12-28T17:00:00Z', PlazoIngreso::limiteIso('2026-12-27', '14:00'));
    }

    //Un segundo antes del límite todavía se puede registrar; en el límite, ya no
    public function testVencidoJustoEnElLimite(): void
    {
        $this->assertFalse(PlazoIngreso::vencido('2026-12-27', '14:00', '2026-12-28 16:59:59'));
        $this->assertTrue(PlazoIngreso::vencido('2026-12-27', '14:00', '2026-12-28 17:00:00'));
        $this->assertTrue(PlazoIngreso::vencido('2026-12-27', '14:00', '2027-01-03 10:00:00'));
    }

    //El descuento se redondea a centavos, sin floats, también en importes muy grandes
    public function testElDescuentoRedondeaACentavos(): void
    {
        $this->assertSame('315000.00', Estadia::conDescuento('350000.00', '10.00'));
        $this->assertSame('350000.00', Estadia::conDescuento('350000.00', '0.00'));
        $this->assertSame('85.01', Estadia::conDescuento('100.01', '15.00'));//85.0085 → 85.01
        $this->assertSame('84.99', Estadia::conDescuento('99.99', '15.00'));//84.9915 → 84.99
        $this->assertSame('8106479329266893.71', Estadia::conDescuento('9007199254740993.01', '10.00'));
    }
}
