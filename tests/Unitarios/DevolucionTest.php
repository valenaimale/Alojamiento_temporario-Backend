<?php

namespace Tests\Unitarios;

use App\Validacion\Devolucion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//Cuánto se devuelve al cancelar: el % de la seña según los días hasta el cobro del saldo, y el saldo entero si se pagó.
final class DevolucionTest extends TestCase
{
    private const BILLETERA = '0x000000000000000000000000000000000000beef';

    //Una reserva con fecha de cobro el 30/11/2026 y retenciones de 30 %, 50 % y 70 % de la seña
    private function reserva(): array
    {
        return [
            'fecha_cobro' => '2026-11-30',
            'retencion_mas_de_30' => '30.00', 'retencion_de_15_a_30' => '50.00', 'retencion_menos_de_15' => '70.00',
        ];
    }

    //Un pago confirmado con 1 ETH = $100.000.000
    private function pago(string $tipo, string $pesos, string $wei, string $billetera = self::BILLETERA): array
    {
        return ['tipo' => $tipo, 'monto_pesos' => $pesos, 'monto_wei' => $wei, 'pesos_por_eth' => '100000000.00', 'billetera' => $billetera];
    }

    //Los bordes de los tramos: 31 días es "más de 30", 30 y 15 son "de 15 a 30", 14 es "menos de 15"
    #[DataProvider('tramos')]
    public function testElTramoSegunLosDiasHastaElCobro(string $hoy, int $dias, string $tramo, string $porcentaje): void
    {
        $resultado = Devolucion::calcular($this->reserva(), [], new \DateTimeImmutable($hoy));

        $this->assertSame([$dias, $tramo, $porcentaje], [$resultado['dias_hasta_cobro'], $resultado['tramo'], $resultado['porcentaje_retencion']]);
    }

    public static function tramos(): array
    {
        return [
            '31 días'           => ['2026-10-30', 31, Devolucion::MAS_DE_30, '30.00'],
            '30 días'           => ['2026-10-31', 30, Devolucion::DE_15_A_30, '50.00'],
            '15 días'           => ['2026-11-15', 15, Devolucion::DE_15_A_30, '50.00'],
            '14 días'           => ['2026-11-16', 14, Devolucion::MENOS_DE_15, '70.00'],
            'el día del cobro'  => ['2026-11-30', 0, Devolucion::MENOS_DE_15, '70.00'],
            'pasado el cobro'   => ['2026-12-02', -2, Devolucion::MENOS_DE_15, '70.00'],
        ];
    }

    //Con la seña de $42.000 pagada, 20 días antes del cobro: se retiene el 50 % y se devuelve el otro 50 %
    public function testSeRetieneElPorcentajeDeLaSena(): void
    {
        $resultado = Devolucion::calcular($this->reserva(), [$this->pago('sena', '42000.00', '420000000000000')], new \DateTimeImmutable('2026-11-10'));

        $this->assertSame([
            'tramo' => Devolucion::DE_15_A_30, 'dias_hasta_cobro' => 20, 'porcentaje_retencion' => '50.00',
            'pagado_sena' => '42000.00', 'pagado_saldo' => '0.00', 'retencion' => '21000.00', 'devolucion' => '21000.00',
            'devolucion_wei' => '210000000000000', 'billetera' => self::BILLETERA, 'pesos_por_eth' => '100000000.00',
        ], $resultado);
    }

    //Con el saldo pagado no se devuelve nada (decisión #62, reemplaza a la #31): solo una excepción del propietario
    public function testConElSaldoPagadoNoSeDevuelveNada(): void
    {
        $pagos = [$this->pago('sena', '42000.00', '420000000000000'), $this->pago('saldo', '168000.00', '1680000000000000')];

        $resultado = Devolucion::calcular($this->reserva(), $pagos, new \DateTimeImmutable('2026-12-01'));

        $this->assertSame(['100.00', '210000.00', '0.00', '0'], [
            $resultado['porcentaje_retencion'], $resultado['retencion'], $resultado['devolucion'], $resultado['devolucion_wei'],
        ]);
    }

    //Si cancela el propietario se devuelve todo lo que pagó, seña y saldo, sin importar el tramo (decisión #63)
    public function testSiCancelaElPropietarioSeDevuelveTodo(): void
    {
        $pagos = [$this->pago('sena', '42000.00', '420000000000000'), $this->pago('saldo', '168000.00', '1680000000000000')];

        $resultado = Devolucion::total($this->reserva(), $pagos, new \DateTimeImmutable('2026-12-01'));

        $this->assertSame(['0.00', '0.00', '210000.00', '2100000000000000'], [
            $resultado['porcentaje_retencion'], $resultado['retencion'], $resultado['devolucion'], $resultado['devolucion_wei'],
        ]);
    }

    //Sin pagos (una pendiente) no hay nada que devolver; los pagos que no son del huésped no cuentan
    public function testSinPagosNoSeDevuelveNada(): void
    {
        $resultado = Devolucion::calcular($this->reserva(), [$this->pago('devolucion', '100.00', '1000000000000')], new \DateTimeImmutable('2026-10-01'));

        $this->assertSame(['0.00', '0.00', '0', null], [$resultado['retencion'], $resultado['devolucion'], $resultado['devolucion_wei'], $resultado['billetera']]);
    }

    //Se devuelve a la billetera que pagó la seña, aunque el saldo lo haya pagado otra
    public function testSeDevuelveALaBilleteraDeLaSena(): void
    {
        $otra = '0x00000000000000000000000000000000000000aa';
        $pagos = [$this->pago('saldo', '168000.00', '1680000000000000', $otra), $this->pago('sena', '42000.00', '420000000000000')];

        $this->assertSame(self::BILLETERA, Devolucion::calcular($this->reserva(), $pagos, new \DateTimeImmutable('2026-11-10'))['billetera']);
    }

    //Los centavos: la retención en pesos se redondea; en wei se trunca, sin perder ni ganar más de 1 wei
    public function testRedondeo(): void
    {
        $reserva = ['retencion_mas_de_30' => '33.33'] + $this->reserva();

        $resultado = Devolucion::calcular($reserva, [$this->pago('sena', '100.01', '1000100000000')], new \DateTimeImmutable('2026-10-01'));

        $this->assertSame(['33.33', '66.68', '666766670000'], [$resultado['retencion'], $resultado['devolucion'], $resultado['devolucion_wei']]);
    }
}
