<?php

namespace Tests\Unitarios;

use App\Validacion\CobroDelSaldo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//La regla del saldo (decisión #75 de docs/pagos-decisiones.md): el saldo se paga hasta el final del día de la fecha de
//cobro, sin días de gracia, y se le recuerda al huésped 3 días antes, el día anterior y el mismo día.
//Se prueban limiteDePago() y recordatorioQueToca() solas, pasándoles las fechas: así no tocan la base ni el reloj.
//Importa que estas cuentas sean exactas porque el plazo es la fecha que el mail le promete al huésped y la que después
//usa la cancelación automática: si no coinciden, se le cancela la reserva un día antes de lo que se le dijo.
final class CobroDelSaldoTest extends TestCase
{
    //Sin días de gracia: el último día para pagar es la misma fecha de cobro
    public function test_ElPlazoEsLaFechaDeCobroSinGracia(): void
    {
        // Arrange
        $fechaCobro = '2026-12-01';

        // Act
        $limite = CobroDelSaldo::limiteDePago($fechaCobro, '2026-12-06');

        // Assert
        $this->assertSame('2026-12-01', $limite);
    }

    //Nunca después del ingreso: sin el saldo no se registra el ingreso (#25). No debería pasar (la fecha de cobro siempre
    //es anterior), pero si una reserva vieja la tuviera mal, el plazo se corta en el ingreso.
    public function test_ElPlazoNuncaPasaDelDiaDelIngreso(): void
    {
        // Arrange
        $ingreso = '2026-12-02';

        // Act
        $limite = CobroDelSaldo::limiteDePago('2026-12-05', $ingreso);

        // Assert
        $this->assertSame('2026-12-02', $limite);
    }

    //Qué recordatorio le toca cada día, contando hacia el último día para pagar (el 5/12)
    #[DataProvider('dias')]
    public function test_ElRecordatorioQueTocaSegunLosDiasQueFaltan(string $hoy, ?int $esperado): void
    {
        // Arrange
        $limite = '2026-12-05';

        // Act
        $numero = CobroDelSaldo::recordatorioQueToca($hoy, $limite);

        // Assert
        $this->assertSame($esperado, $numero);
    }

    public static function dias(): array
    {
        return [
            'faltan 10 días: todavía no'      => ['2026-11-25', null],
            'faltan 4 días: todavía no'       => ['2026-11-30', null],
            'faltan 3 días: el primero'       => ['2026-12-02', 1],
            'faltan 2 días: sigue el primero' => ['2026-12-03', 1],
            'falta 1 día: el segundo'         => ['2026-12-04', 2],
            'el mismo día: el tercero'        => ['2026-12-05', 3],
            'se le pasó: ninguno'             => ['2026-12-06', null],
        ];
    }

    //El plazo cruza el cambio de mes sin inventar un 32 de noviembre: a 3 días del 1/12 es el 28/11
    public function test_LosDiasSeCuentanCruzandoElCambioDeMes(): void
    {
        // Arrange
        $hoy = '2026-11-28';

        // Act
        $numero = CobroDelSaldo::recordatorioQueToca($hoy, '2026-12-01');

        // Assert
        $this->assertSame(1, $numero);
    }

    //Son tres avisos, el último el mismo día: es lo que dice el plan de la entrega del 28/11
    public function test_SonTresAvisosA3DiasA1DiaYElMismoDia(): void
    {
        // Arrange
        $esperados = [3, 1, 0];

        // Act
        $avisos = CobroDelSaldo::AVISOS;

        // Assert
        $this->assertSame($esperados, $avisos);
    }
}
