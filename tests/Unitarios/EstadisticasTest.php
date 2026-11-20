<?php

namespace Tests\Unitarios;

use App\Validacion\Devolucion;
use App\Validacion\Estadisticas;
use PHPUnit\Framework\TestCase;

//Los cálculos de las estadísticas de una unidad (RF-12). Estadisticas::calcular() recibe las reservas y no usa la
//base, así que se prueba sin servidor.
//El primer caso son los números de la pantalla que diseñamos en la Entrega 3 (sección 22, "Estadísticas"):
//ocupación 78 %, tarifa promedio $81.400, estadía promedio 4,2 noches, 1 cancelación con más de 30 días,
//2 de 15 a 30 y 0 con menos de 15, y 3 huéspedes recurrentes. Tienen que salir exactos.
final class EstadisticasTest extends TestCase
{
    private const PRIMERA_NOCHE = '2027-01-01';
    private const ULTIMA_NOCHE  = '2027-02-19';//50 noches: 31 de enero + 19 de febrero
    private const PRECIO_NOCHE  = 81400;

    //La temporada ya terminada: la ocupación se mide completa
    private const DESPUES = '2027-03-01';

    public function testLaTemporadaDelDisenioDaLosNumerosDeLaPantalla(): void
    {
        $estadisticas = Estadisticas::calcular(
            self::temporadaAlta(),
            self::PRIMERA_NOCHE,
            self::ULTIMA_NOCHE,
            new \DateTimeImmutable(self::DESPUES)
        );

        $this->assertSame(50, $estadisticas['noches_disponibles']);
        $this->assertSame(39, $estadisticas['noches_ocupadas']);
        $this->assertSame('78.0', $estadisticas['ocupacion']);
        $this->assertSame('81400.00', $estadisticas['tarifa_promedio']);
        $this->assertSame('4.2', $estadisticas['estadia_promedio']);
        $this->assertSame(10, $estadisticas['reservas']);
        $this->assertSame(3, $estadisticas['huespedes_recurrentes']);
        $this->assertSame([
            Devolucion::MAS_DE_30              => 1,
            Devolucion::DE_15_A_30             => 2,
            Devolucion::MENOS_DE_15            => 0,
            Estadisticas::OTRAS_CANCELACIONES  => 0,
        ], $estadisticas['cancelaciones']);
        $this->assertTrue($estadisticas['hay_datos']);
    }

    //Una reserva que arranca en una temporada y termina en la otra le aporta a cada una las noches que le tocan
    public function testUnaReservaACaballoDeDosTemporadasAportaSusNochesACadaUna(): void
    {
        $reservas = [self::reserva([
            'id'            => 1,
            'fecha_ingreso' => '2027-01-30',//2 noches en enero (30 y 31) y 3 en febrero (1, 2 y 3)
            'fecha_egreso'  => '2027-02-04',
            'importe_total' => '407000.00',//5 noches a $81.400
        ])];

        $enero   = Estadisticas::calcular($reservas, '2027-01-01', '2027-01-31', new \DateTimeImmutable(self::DESPUES));
        $febrero = Estadisticas::calcular($reservas, '2027-02-01', '2027-02-28', new \DateTimeImmutable(self::DESPUES));

        $this->assertSame(2, $enero['noches_ocupadas']);
        $this->assertSame(3, $febrero['noches_ocupadas']);
        //la estadía entera cuenta donde EMPIEZA: 5 noches en enero y ninguna reserva en febrero
        $this->assertSame('5.0', $enero['estadia_promedio']);
        $this->assertSame(1, $enero['reservas']);
        $this->assertSame('0.0', $febrero['estadia_promedio']);
        $this->assertSame(0, $febrero['reservas']);
        //la tarifa promedio por noche es la misma en las dos: es el precio de la noche, no el de la reserva
        $this->assertSame('81400.00', $enero['tarifa_promedio']);
        $this->assertSame('81400.00', $febrero['tarifa_promedio']);
    }

    //Si la temporada está en curso, el denominador llega hasta anoche: si no, toda temporada empezada daría una
    //ocupación falsamente baja
    public function testLaTemporadaEnCursoSeMideHastaAnoche(): void
    {
        $reservas = [self::reserva([
            'id'            => 1,
            'fecha_ingreso' => '2027-01-01',
            'fecha_egreso'  => '2027-01-05',//4 noches
            'importe_total' => '325600.00',
        ])];

        //el 11 de enero ya pasaron 10 noches de las 50, y 4 estuvieron ocupadas
        $enCurso = Estadisticas::calcular($reservas, self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable('2027-01-11'));

        $this->assertSame(10, $enCurso['noches_disponibles']);
        $this->assertSame(4, $enCurso['noches_ocupadas']);
        $this->assertSame('40.0', $enCurso['ocupacion']);

        //la misma reserva, con la temporada terminada: 4 de 50
        $terminada = Estadisticas::calcular($reservas, self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable(self::DESPUES));

        $this->assertSame(50, $terminada['noches_disponibles']);
        $this->assertSame('8.0', $terminada['ocupacion']);
    }

    //El primer día de la temporada todavía no hay noches medidas: no se divide por cero
    public function testElPrimerDiaDeLaTemporadaNoDividePorCero(): void
    {
        $estadisticas = Estadisticas::calcular([], self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable(self::PRIMERA_NOCHE));

        $this->assertSame(0, $estadisticas['noches_disponibles']);
        $this->assertSame('0.0', $estadisticas['ocupacion']);
        $this->assertFalse($estadisticas['hay_datos']);
    }

    //Una extensión (decisión #70) no es una reserva aparte: sus noches alargan la estadía de la que extiende
    public function testLaExtensionAlargaLaEstadiaEnLugarDeContarComoOtraReserva(): void
    {
        $estadisticas = Estadisticas::calcular([
            self::reserva([
                'id'            => 1,
                'fecha_ingreso' => '2027-01-01',
                'fecha_egreso'  => '2027-01-05',//4 noches
                'importe_total' => '325600.00',
            ]),
            self::reserva([
                'id'                  => 2,
                'extiende_reserva_id' => 1,
                'fecha_ingreso'       => '2027-01-05',
                'fecha_egreso'        => '2027-01-08',//3 noches más
                'importe_total'       => '244200.00',
            ]),
        ], self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable(self::DESPUES));

        $this->assertSame(1, $estadisticas['reservas']);
        $this->assertSame('7.0', $estadisticas['estadia_promedio']);
        $this->assertSame(7, $estadisticas['noches_ocupadas']);
        //y el huésped no se vuelve recurrente por haber extendido
        $this->assertSame(0, $estadisticas['huespedes_recurrentes']);
    }

    //Solo ocupan la unidad las confirmadas y las finalizadas: la pendiente puede expirar, la cancelada no ocupó
    //nada y en el no-show (decisión #67) el huésped nunca llegó
    public function testLaPendienteLaExpiradaYElNoShowNoOcupan(): void
    {
        $reservas = [];
        foreach (['pendiente', 'expirada', 'no_show', 'cancelada'] as $numero => $estado) {
            $reservas[] = self::reserva([
                'id'            => $numero + 1,
                'estado'        => $estado,
                'fecha_ingreso' => '2027-01-0' . ($numero + 1),
                'fecha_egreso'  => '2027-01-0' . ($numero + 2),
            ]);
        }

        $estadisticas = Estadisticas::calcular($reservas, self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable(self::DESPUES));

        $this->assertSame(0, $estadisticas['noches_ocupadas']);
        $this->assertSame(0, $estadisticas['reservas']);
        $this->assertSame('0.00', $estadisticas['tarifa_promedio']);
    }

    //La cancelación del propietario no entra en ningún tramo: no hubo retención por política (decisión #63)
    public function testLaCancelacionDelPropietarioNoEntraEnNingunTramo(): void
    {
        $estadisticas = Estadisticas::calcular([
            self::reserva([
                'id'             => 1,
                'estado'         => 'cancelada',
                'fecha_cobro'    => '2027-01-20',
                'cancelada_en'   => '2026-12-01 10:00:00',
                'cancelada_por'  => 'propietario',
            ]),
        ], self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable(self::DESPUES));

        $this->assertSame(1, $estadisticas['cancelaciones'][Estadisticas::OTRAS_CANCELACIONES]);
        $this->assertSame(0, $estadisticas['cancelaciones'][Devolucion::MAS_DE_30]);
        $this->assertTrue($estadisticas['hay_datos']);//hubo movimiento, aunque no haya noches ocupadas
    }

    //Los tramos cortan igual que la devolución: más de 30 días hasta el cobro, de 15 a 30, o menos de 15
    public function testLosTramosCortanEnLosMismosDiasQueLaDevolucion(): void
    {
        $tramo = fn (string $canceladaEn, string $cobro) => Estadisticas::tramoDeCancelacion([
            'cancelada_en'  => $canceladaEn . ' 12:00:00',
            'fecha_cobro'   => $cobro,
            'cancelada_por' => 'huesped',
        ]);

        $this->assertSame(Devolucion::MAS_DE_30,   $tramo('2027-01-01', '2027-02-01'));//31 días
        $this->assertSame(Devolucion::DE_15_A_30,  $tramo('2027-01-01', '2027-01-31'));//30 días, el borde de arriba
        $this->assertSame(Devolucion::DE_15_A_30,  $tramo('2027-01-01', '2027-01-16'));//15 días, el borde de abajo
        $this->assertSame(Devolucion::MENOS_DE_15, $tramo('2027-01-01', '2027-01-15'));//14 días
        $this->assertSame(Devolucion::MENOS_DE_15, $tramo('2027-01-20', '2027-01-15'));//canceló con el saldo ya cobrado
    }

    //Un huésped es recurrente cuando volvió: con una sola reserva, no
    public function testHaceFaltaVolverParaSerRecurrente(): void
    {
        $unaSola = Estadisticas::calcular(
            [self::reserva(['id' => 1, 'huesped_id' => 7])],
            self::PRIMERA_NOCHE,
            self::ULTIMA_NOCHE,
            new \DateTimeImmutable(self::DESPUES)
        );
        $this->assertSame(0, $unaSola['huespedes_recurrentes']);

        //la segunda reserva del mismo huésped, en otra temporada: igual cuenta, porque el recurrente es histórico
        $volvio = Estadisticas::calcular([
            self::reserva(['id' => 1, 'huesped_id' => 7]),
            self::reserva(['id' => 2, 'huesped_id' => 7, 'fecha_ingreso' => '2026-07-01', 'fecha_egreso' => '2026-07-05']),
        ], self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable(self::DESPUES));

        $this->assertSame(1, $volvio['huespedes_recurrentes']);
    }

    //La temporada sin una sola reserva: todo en cero y hay_datos en false, que es lo que el front usa para mostrar
    //"Todavía no hay datos de esta temporada"
    public function testLaTemporadaSinReservasAvisaQueNoHayDatos(): void
    {
        $estadisticas = Estadisticas::calcular([], self::PRIMERA_NOCHE, self::ULTIMA_NOCHE, new \DateTimeImmutable(self::DESPUES));

        $this->assertFalse($estadisticas['hay_datos']);
        $this->assertSame(0, $estadisticas['noches_ocupadas']);
        $this->assertSame('0.0', $estadisticas['ocupacion']);
        $this->assertSame('0.00', $estadisticas['tarifa_promedio']);
        $this->assertSame('0.0', $estadisticas['estadia_promedio']);
        $this->assertSame(0, $estadisticas['huespedes_recurrentes']);
    }

    //Una temporada con las fechas al revés no rompe: devuelve la temporada vacía
    public function testUnaTemporadaConFechasAlRevesNoRompe(): void
    {
        $estadisticas = Estadisticas::calcular(self::temporadaAlta(), '2027-02-19', '2027-01-01');

        $this->assertFalse($estadisticas['hay_datos']);
        $this->assertSame(0, $estadisticas['noches_disponibles']);
    }

    public function testLasNochesFueraDeLaVentanaNoCuentan(): void
    {
        $noches = fn (string $ingreso, string $egreso) => Estadisticas::nochesEnVentana(
            new \DateTimeImmutable($ingreso),
            new \DateTimeImmutable($egreso),
            new \DateTimeImmutable('2027-01-10'),
            new \DateTimeImmutable('2027-01-20')
        );

        $this->assertSame(0, $noches('2027-01-01', '2027-01-10'));//termina justo cuando empieza la ventana
        $this->assertSame(0, $noches('2027-01-20', '2027-01-25'));//empieza justo cuando termina
        $this->assertSame(10, $noches('2027-01-01', '2027-02-01'));//la contiene entera
        $this->assertSame(2, $noches('2027-01-08', '2027-01-12'));//entra por el principio
        $this->assertSame(1, $noches('2027-01-19', '2027-01-23'));//entra por el final
    }

    //Las 13 reservas de la temporada del diseño: 10 que ocuparon y 3 canceladas.
    //Las 9 primeras son de 4 noches y la décima de 6, de las que solo 3 caen dentro de la temporada: 39 noches
    //ocupadas de las 50, y 42 noches de estadía repartidas en 10 reservas (4,2 de promedio).
    //Los huéspedes 1, 2 y 3 reservaron dos veces cada uno: 3 recurrentes.
    private static function temporadaAlta(): array
    {
        $reservas = [];
        $huespedes = [1, 1, 2, 2, 3, 3, 4, 5, 6];
        foreach ($huespedes as $numero => $huesped) {
            $ingreso = (new \DateTimeImmutable(self::PRIMERA_NOCHE))->modify('+' . ($numero * 4) . ' days');
            $reservas[] = self::reserva([
                'id'            => $numero + 1,
                'huesped_id'    => $huesped,
                'fecha_ingreso' => $ingreso->format('Y-m-d'),
                'fecha_egreso'  => $ingreso->modify('+4 days')->format('Y-m-d'),
                'importe_total' => number_format(self::PRECIO_NOCHE * 4, 2, '.', ''),
            ]);
        }

        //la última empieza dentro de la temporada pero se pasa de la última noche: solo 3 de sus 6 noches cuentan
        $reservas[] = self::reserva([
            'id'            => 10,
            'huesped_id'    => 7,
            'fecha_ingreso' => '2027-02-17',
            'fecha_egreso'  => '2027-02-23',
            'importe_total' => number_format(self::PRECIO_NOCHE * 6, 2, '.', ''),
        ]);

        //las canceladas: una con más de 30 días hasta el cobro del saldo y dos de 15 a 30
        $canceladas = [
            ['2026-11-01', '2026-12-10'],//39 días
            ['2027-01-01', '2027-01-21'],//20 días
            ['2027-01-05', '2027-01-25'],//20 días
        ];
        foreach ($canceladas as $numero => [$canceladaEn, $cobro]) {
            $reservas[] = self::reserva([
                'id'            => 11 + $numero,
                'huesped_id'    => 20 + $numero,
                'estado'        => 'cancelada',
                'fecha_ingreso' => '2027-01-1' . $numero,
                'fecha_egreso'  => '2027-01-2' . $numero,
                'fecha_cobro'   => $cobro,
                'cancelada_en'  => $canceladaEn . ' 09:30:00',
                'cancelada_por' => 'huesped',
            ]);
        }

        return $reservas;
    }

    //Una reserva como la guarda la tabla, con los valores que no importan en cada caso ya puestos
    private static function reserva(array $campos): array
    {
        return array_merge([
            'id'                  => 1,
            'huesped_id'          => 1,
            'extiende_reserva_id' => null,
            'fecha_ingreso'       => '2027-01-01',
            'fecha_egreso'        => '2027-01-05',
            'fecha_cobro'         => null,
            'estado'              => 'finalizada',
            'importe_total'       => '325600.00',
            'cancelada_en'        => null,
            'cancelada_por'       => null,
        ], $campos);
    }
}
