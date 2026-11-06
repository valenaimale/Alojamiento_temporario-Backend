<?php

namespace Tests\Unitarios;

use App\Validacion\CobroDelSaldo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//El plazo para pagar el saldo (decisión #23 de docs/pagos-decisiones.md): desde la fecha de cobro, el huésped
//tiene dias_vencimiento_saldo días, o hasta el día del ingreso si llega antes. Pasado ese día, la reserva se
//cancela sola con el tramo de menos de 15 días.
//Se prueba CobroDelSaldo::limiteDePago() sola, pasándole los días: así no toca la base ni los parámetros.
//Importa que esta cuenta sea exacta porque es la fecha que el mail le promete al huésped y la que después
//usa la cancelación automática: si no coinciden, se le cancela la reserva un día antes de lo que se le dijo.
final class CobroDelSaldoTest extends TestCase
{
    //El caso normal: reservó con tiempo, así que tiene los 3 días completos desde la fecha de cobro
    public function testConTiempoDeSobraElPlazoEsLaFechaDeCobroMasLosDias(): void
    {
        $this->assertSame('2026-12-04', CobroDelSaldo::limiteDePago('2026-12-01', '2026-12-20', 3));
    }

    //Si el ingreso cae antes de que se cumplan los días, el plazo se corta ahí: ese día lo puede pagar al
    //llegar, pero no después, porque sin el saldo no se registra el ingreso (#25)
    public function testElPlazoNuncaPasaDelDiaDelIngreso(): void
    {
        $this->assertSame('2026-12-02', CobroDelSaldo::limiteDePago('2026-12-01', '2026-12-02', 3));
    }

    //Reservó para mañana: la fecha de cobro es el día de la reserva y el ingreso es al día siguiente
    public function testUnaReservaDeUltimoMomentoTienePlazoHastaElIngreso(): void
    {
        $this->assertSame('2026-12-06', CobroDelSaldo::limiteDePago('2026-12-05', '2026-12-06', 3));
    }

    //El mismo día de ingreso que de cobro (reservó para hoy): le queda ese día
    public function testSiCobroEIngresoSonElMismoDiaElPlazoEsEseDia(): void
    {
        $this->assertSame('2026-12-05', CobroDelSaldo::limiteDePago('2026-12-05', '2026-12-05', 3));
    }

    //Nunca devuelve una fecha anterior a la de cobro: no se le puede vencer el plazo antes de empezar a
    //pedírselo. No debería pasar (fecha_cobro siempre es anterior al ingreso), pero si pasara, le queda ese día.
    public function testNoDevuelveUnaFechaAnteriorALaDeCobro(): void
    {
        $this->assertSame('2026-12-10', CobroDelSaldo::limiteDePago('2026-12-10', '2026-12-08', 3));
    }

    //El plazo es configurable: con el parámetro en otro valor, la fecha se corre
    #[DataProvider('plazos')]
    public function testElPlazoSigueAlParametro(int $dias, string $esperado): void
    {
        $this->assertSame($esperado, CobroDelSaldo::limiteDePago('2026-12-01', '2026-12-31', $dias));
    }

    public static function plazos(): array
    {
        return [
            'sin días de gracia' => [0, '2026-12-01'],
            'un día'             => [1, '2026-12-02'],
            'los 3 por defecto'  => [3, '2026-12-04'],
            'una semana'         => [7, '2026-12-08'],
        ];
    }

    //Cambio de mes: el plazo cruza a diciembre sin inventar un 32 de noviembre
    public function testElPlazoCruzaElCambioDeMes(): void
    {
        $this->assertSame('2026-12-02', CobroDelSaldo::limiteDePago('2026-11-29', '2026-12-20', 3));
    }
}
