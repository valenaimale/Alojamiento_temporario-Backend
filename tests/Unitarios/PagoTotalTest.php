<?php

namespace Tests\Unitarios;

use App\Validacion\Estadia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//Pago total al reservar (decisión #75 de docs/pagos-decisiones.md): quien reserva con dias_cobro_saldo días o menos
//de anticipación (5 por defecto) paga todo junto, porque la fecha de cobro del saldo ya llegó.
//Se prueba Estadia::pagaTodoAlReservar() sola, con las fechas y los días: así no toca la base ni el reloj.
final class PagoTotalTest extends TestCase
{
    //Los bordes con 5 días: 5 o menos es pago total, 6 o más es seña y saldo
    #[DataProvider('anticipaciones')]
    public function test_PagaTodoSiReservaConCincoDiasOMenos(string $ingreso, bool $esperado): void
    {
        // Arrange
        $hoy = new \DateTimeImmutable('2026-11-20');

        // Act
        $pagaTodo = Estadia::pagaTodoAlReservar(new \DateTimeImmutable($ingreso), $hoy, 5);

        // Assert
        $this->assertSame($esperado, $pagaTodo);
    }

    public static function anticipaciones(): array
    {
        return [
            'para hoy'          => ['2026-11-20', true],
            'para mañana'       => ['2026-11-21', true],
            'con 5 días'        => ['2026-11-25', true],
            'con 6 días'        => ['2026-11-26', false],
            'con un mes'        => ['2026-12-20', false],
        ];
    }

    //La hora del día no cambia la cuenta: se comparan días de calendario (a las 23:30 de hoy sigue siendo hoy)
    public function test_LaHoraDelDiaNoCambiaLaCuenta(): void
    {
        // Arrange
        $hoyALaNoche = new \DateTimeImmutable('2026-11-20 23:30:00');

        // Act
        $pagaTodo = Estadia::pagaTodoAlReservar(new \DateTimeImmutable('2026-11-26'), $hoyALaNoche, 5);

        // Assert
        $this->assertFalse($pagaTodo);
    }

    //El corte sigue al parámetro dias_cobro_saldo: con 7, reservar con 7 días ya es pago total
    public function test_ElCorteSigueAlParametro(): void
    {
        // Arrange
        $hoy = new \DateTimeImmutable('2026-11-20');

        // Act
        $pagaTodo = Estadia::pagaTodoAlReservar(new \DateTimeImmutable('2026-11-27'), $hoy, 7);

        // Assert
        $this->assertTrue($pagaTodo);
    }
}
