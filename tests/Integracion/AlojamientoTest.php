<?php

namespace Tests\Integracion;

//El detalle de una unidad para el huésped (GET /alojamiento) y su calendario (GET /alojamiento/calendario), US3.2.
//Los dos son públicos y solo muestran unidades publicadas y disponibles.
final class AlojamientoTest extends CasoDeIntegracion
{
    private int $propietarioId;

    //Antes de cada test: un propietario para las unidades
    protected function setUp(): void
    {
        $this->propietarioId = $this->crearPropietarioEnBase()['id'];
    }

    //Una unidad publicada con dos fotos y $70.000 por noche para los próximos 90 días. Devuelve su id.
    private function unidadPublicada(array $cambios = []): int
    {
        $unidadId = $this->crearUnidadEnBase($this->propietarioId, array_merge([
            'nombre' => 'Cabaña del Mar', 'estado_publicacion' => 'publicada', 'capacidad' => 4,
            'caracteristicas' => 'A dos cuadras de la playa', 'deposito_garantia' => 50000,
        ], $cambios));
        $this->agregarFotoEnBase($unidadId, 1);
        $this->pdo()->prepare('INSERT INTO fotos_unidad (unidad_id, url, orden) VALUES (?, ?, 0)')
            ->execute([$unidadId, 'https://res.cloudinary.com/ynclnyuh/image/upload/unidades/principal.jpg']);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $this->propietarioId);
        return $unidadId;
    }

    //El detalle sin sesión, con las fechas y personas que pida el test
    private function detalle(int $unidadId, array $parametros = [])
    {
        return $this->cliente()->get('/alojamiento', ['id' => $unidadId] + $parametros);
    }

    // ---------- detalle ----------

    //Sin fechas: los datos de la unidad (sin la dirección), las fotos en orden, la seña, la política y el depósito
    public function testDevuelveLosDatosSinLaDireccion(): void
    {
        $unidadId = $this->unidadPublicada();

        $respuesta = $this->detalle($unidadId);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'alojamiento' => [
                'id'                => $unidadId,
                'nombre'            => 'Cabaña del Mar',
                'localidad'         => 'Las Grutas',
                'provincia'         => 'Río Negro',
                'capacidad'         => 4,
                'caracteristicas'   => 'A dos cuadras de la playa',
                'fotos'             => [
                    'https://res.cloudinary.com/ynclnyuh/image/upload/unidades/principal.jpg',
                    'https://res.cloudinary.com/ynclnyuh/image/upload/unidades/prueba.jpg',
                ],
                'deposito_garantia' => '50000.00',
                'porcentaje_sena'   => '20.00',
                'politica'          => [
                    'retencion_mas_de_30'   => '30.00',
                    'retencion_de_15_a_30'  => '50.00',
                    'retencion_menos_de_15' => '70.00',
                    'dias_cobro_saldo'      => 5,
                ],
            ],
            'estadia' => null,
        ], $respuesta->json());
        $this->assertStringNotContainsString('Costanera 123', $respuesta->cuerpo);//la dirección no viaja, en ningún campo
    }

    //Con fechas disponibles: las noches, el total y la seña (20 % del total)
    public function testConFechasDisponiblesDaElTotalYLaSena(): void
    {
        $unidadId = $this->unidadPublicada();

        $respuesta = $this->detalle($unidadId, ['ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+15 days'), 'personas' => 4]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['disponible' => true, 'noches' => 5, 'precio_total' => '350000.00', 'sena' => '70000.00'], $respuesta->json()['estadia']);
    }

    //Con fechas que no se pueden reservar, dice por qué (el mismo cálculo que la búsqueda); el motivo de un bloqueo no se ve
    public function testConFechasNoDisponiblesDicePorQue(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->pdo()->prepare("INSERT INTO bloqueos_calendario (unidad_id, fecha_desde, fecha_hasta, motivo, detalle, creado_por) VALUES (?, ?, ?, 'otro', 'Viene mi suegra', ?)")
            ->execute([$unidadId, $this->fecha('+20 days'), $this->fecha('+22 days'), $this->propietarioId]);
        $this->crearTemporadaEnBase($unidadId, 'alta', $this->fecha('+30 days'), $this->fecha('+40 days'), 7);
        $motivo = fn (string $ingreso, string $egreso, array $extra = []) => $this->detalle($unidadId, ['ingreso' => $this->fecha($ingreso), 'egreso' => $this->fecha($egreso)] + $extra)->json()['estadia'];

        $this->assertSame(['disponible' => false, 'motivo' => 'Hay noches ocupadas en esas fechas'], $motivo('+19 days', '+21 days'));
        $this->assertSame(['disponible' => false, 'motivo' => 'Mínimo 7 noches en temporada alta'], $motivo('+30 days', '+33 days'));
        $this->assertSame(['disponible' => false, 'motivo' => 'Hay noches sin precio en esas fechas'], $motivo('+89 days', '+92 days'));
        $this->assertSame(['disponible' => false, 'motivo' => 'Tiene lugar para 4 personas'], $motivo('+10 days', '+12 days', ['personas' => 5]));

        $respuesta = $this->detalle($unidadId, ['ingreso' => $this->fecha('+19 days'), 'egreso' => $this->fecha('+21 days')]);
        $this->assertStringNotContainsString('suegra', $respuesta->cuerpo);
    }

    //Fechas inválidas: 422 con el mismo mensaje que la búsqueda; una sola fecha también es un error
    public function testFechasInvalidas(): void
    {
        $unidadId = $this->unidadPublicada();

        $hoy = $this->detalle($unidadId, ['ingreso' => $this->fecha(), 'egreso' => $this->fecha('+2 days')]);
        $this->assertSame(422, $hoy->codigo);
        $this->assertSame('El ingreso tiene que ser de mañana en adelante', $hoy->json()['error']);

        $soloIngreso = $this->detalle($unidadId, ['ingreso' => $this->fecha('+2 days')]);
        $this->assertSame(422, $soloIngreso->codigo);
        $this->assertSame('Elegí las fechas de ingreso y de egreso', $soloIngreso->json()['error']);

        $personas = $this->detalle($unidadId, ['ingreso' => $this->fecha('+2 days'), 'egreso' => $this->fecha('+4 days'), 'personas' => 'muchas']);
        $this->assertSame(422, $personas->codigo);
    }

    //Una unidad en borrador, pausada, fuera de servicio o que no existe: para el huésped "no existe"
    public function testSoloLasPublicadasYDisponibles(): void
    {
        $ids = [
            'en borrador'       => $this->unidadPublicada(['estado_publicacion' => 'borrador']),
            'pausada'           => $this->unidadPublicada(['estado_publicacion' => 'pausada']),
            'fuera de servicio' => $this->unidadPublicada(['disponible' => 0, 'motivo_no_disponible' => 'Se rompió el techo']),
            'inexistente'       => 999999,
            'id inválido'       => 'abc',
        ];
        foreach ($ids as $caso => $id) {
            foreach (['/alojamiento' => [], '/alojamiento/calendario' => ['mes' => substr($this->fecha(), 0, 7)]] as $ruta => $extra) {
                $respuesta = $this->cliente()->get($ruta, ['id' => $id] + $extra);
                $this->assertSame(404, $respuesta->codigo, "$caso: $ruta");
                $this->assertSame('No encontramos ese alojamiento', $respuesta->json()['error']);
            }
        }
    }

    // ---------- calendario ----------

    //Cada noche del mes con su estado: libre (con precio), ocupada, sin precio; y el mínimo de su temporada
    public function testElCalendarioDelMes(): void
    {
        $unidadId = $this->unidadPublicada();
        $mes = new \DateTimeImmutable('first day of next month', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $dia = fn (int $n) => $mes->modify('+' . ($n - 1) . ' days')->format('Y-m-d');//el día n del mes que viene
        $this->crearBloqueoEnBase($unidadId, $dia(5), $dia(6), $this->propietarioId);
        $this->pdo()->prepare('UPDATE precio_noche SET vigente_hasta = NOW() WHERE unidad_id = ? AND fecha = ?')->execute([$unidadId, $dia(10)]);
        $this->crearTemporadaEnBase($unidadId, 'alta', $dia(15), $dia(20), 7);

        $respuesta = $this->cliente()->get('/alojamiento/calendario', ['id' => $unidadId, 'mes' => $mes->format('Y-m')]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $noches = array_column($respuesta->json()['noches'], null, 'fecha');
        $this->assertCount((int) $mes->format('t'), $noches);//una por cada día del mes
        $this->assertSame(['fecha' => $dia(1), 'estado' => 'libre', 'precio' => '70000.00', 'minimo_noches' => 1], $noches[$dia(1)]);
        $this->assertSame(['fecha' => $dia(5), 'estado' => 'ocupada', 'precio' => null, 'minimo_noches' => 1], $noches[$dia(5)]);
        $this->assertSame('ocupada', $noches[$dia(6)]['estado']);
        $this->assertSame('libre', $noches[$dia(7)]['estado']);
        $this->assertSame(['fecha' => $dia(10), 'estado' => 'sin_precio', 'precio' => null, 'minimo_noches' => 1], $noches[$dia(10)]);
        $this->assertSame(7, $noches[$dia(15)]['minimo_noches']);
    }

    //En el mes actual, hoy y los días anteriores son "pasada" (hoy ya no se puede reservar); mañana, libre
    public function testHoyYAntesSonPasadas(): void
    {
        $unidadId = $this->unidadPublicada();

        $respuesta = $this->cliente()->get('/alojamiento/calendario', ['id' => $unidadId, 'mes' => substr($this->fecha(), 0, 7)]);

        $noches = array_column($respuesta->json()['noches'], 'estado', 'fecha');
        $this->assertSame('pasada', $noches[$this->fecha()]);
        $this->assertSame('pasada', $noches[substr($this->fecha(), 0, 8) . '01']);
        if (isset($noches[$this->fecha('+1 day')])) {//si mañana es de este mes
            $this->assertSame('libre', $noches[$this->fecha('+1 day')]);
        }
    }

    //El mes: formato AAAA-MM, desde este mes hasta dentro de dos años
    public function testElMesTieneQueSerValido(): void
    {
        $unidadId = $this->unidadPublicada();
        $mesesInvalidos = [
            '2026-13'  => 'El mes tiene que tener el formato AAAA-MM',
            'diciembre' => 'El mes tiene que tener el formato AAAA-MM',
            substr($this->fecha('-1 month'), 0, 7) => 'Podés ver desde este mes hasta dentro de dos años',
            substr($this->fecha('+25 months'), 0, 7) => 'Podés ver desde este mes hasta dentro de dos años',
        ];
        foreach ($mesesInvalidos as $mes => $mensaje) {
            $respuesta = $this->cliente()->get('/alojamiento/calendario', ['id' => $unidadId, 'mes' => $mes]);
            $this->assertSame(422, $respuesta->codigo, (string) $mes);
            $this->assertSame($mensaje, $respuesta->json()['error'], (string) $mes);
        }
        $this->assertSame(200, $this->cliente()->get('/alojamiento/calendario', ['id' => $unidadId, 'mes' => substr($this->fecha('+24 months'), 0, 7)])->codigo);
    }
}
