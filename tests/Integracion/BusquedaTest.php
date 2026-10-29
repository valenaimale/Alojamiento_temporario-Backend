<?php

namespace Tests\Integracion;

//La búsqueda de unidades disponibles (US3.2): cada condición por separado.
//Cada test crea su propia localidad, así los resultados no se mezclan con las unidades publicadas por otros tests.
final class BusquedaTest extends CasoDeIntegracion
{
    private int $propietarioId;

    //Antes de cada test: un propietario para las unidades
    protected function setUp(): void
    {
        $this->propietarioId = $this->crearPropietarioEnBase()['id'];
    }

    //Una localidad nueva, solo para este test. Devuelve su id.
    //Va en una provincia propia, así no cambia la lista de localidades reales que controla LocalidadesTest.
    private function localidadNueva(bool $activa = true): int
    {
        $this->pdo()->prepare('INSERT INTO localidades (nombre, provincia, activa) VALUES (?, ?, ?)')
            ->execute(['Localidad ' . uniqid(), 'Zona de Prueba', (int) $activa]);
        return (int) $this->pdo()->lastInsertId();
    }

    //Una unidad publicada en esa localidad, con foto y $70.000 por noche para los próximos 60 días. Devuelve su id.
    private function unidadPublicada(int $localidadId, array $cambios = []): int
    {
        $unidadId = $this->crearUnidadEnBase($this->propietarioId, array_merge([
            'localidad_id' => $localidadId, 'estado_publicacion' => 'publicada', 'capacidad' => 4,
        ], $cambios));
        $this->agregarFotoEnBase($unidadId);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+60 days'), 70000, $this->propietarioId);
        return $unidadId;
    }

    //Busca en esa localidad (o en todas, con null) y devuelve la respuesta (el test controla el código)
    private function buscar(?int $localidadId, string $ingreso, string $egreso, int|string $personas = 2)
    {
        return $this->cliente()->get('/buscar', [
            'localidad_id' => $localidadId ?? '', 'ingreso' => $this->fecha($ingreso), 'egreso' => $this->fecha($egreso), 'personas' => $personas,
        ]);
    }

    //Los ids de las unidades encontradas, en el orden en que vinieron
    private function idsEncontrados(?int $localidadId, string $ingreso, string $egreso, int $personas = 2): array
    {
        $respuesta = $this->buscar($localidadId, $ingreso, $egreso, $personas);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        return array_column($respuesta->json()['unidades'], 'id');
    }

    // ---------- lo que devuelve ----------

    //Sin sesión, una unidad disponible aparece con su foto, sus noches y el precio total (la suma de cada noche)
    public function testDevuelveLaUnidadConElPrecioTotal(): void
    {
        $localidadId = $this->localidadNueva();
        $unidadId = $this->unidadPublicada($localidadId, ['nombre' => 'Cabaña del Mar', 'capacidad' => 5]);
        $localidad = $this->pdo()->query("SELECT nombre FROM localidades WHERE id = $localidadId")->fetchColumn();

        $respuesta = $this->buscar($localidadId, '+10 days', '+15 days', 4);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['unidades' => [[
            'id'             => $unidadId,
            'nombre'         => 'Cabaña del Mar',
            'localidad'      => $localidad,
            'provincia'      => 'Zona de Prueba',
            'capacidad'      => 5,
            'foto_principal' => 'https://res.cloudinary.com/ynclnyuh/image/upload/unidades/prueba.jpg',
            'noches'         => 5,
            'precio_total'   => '350000.00',
        ]]], $respuesta->json());
    }

    //Con precios distintos por noche, el total es la suma; y los resultados van del más barato al más caro
    public function testSumaCadaNocheYOrdenaPorPrecio(): void
    {
        $localidadId = $this->localidadNueva();
        $cara = $this->unidadPublicada($localidadId);
        $this->pdo()->prepare('UPDATE precio_noche SET precio = 100000 WHERE unidad_id = ? AND fecha = ?')->execute([$cara, $this->fecha('+11 days')]);
        $barata = $this->unidadPublicada($localidadId);
        $this->pdo()->prepare('UPDATE precio_noche SET precio = 50000 WHERE unidad_id = ?')->execute([$barata]);

        $unidades = $this->buscar($localidadId, '+10 days', '+13 days')->json()['unidades'];

        $this->assertSame([$barata, $cara], array_column($unidades, 'id'));
        $this->assertSame(['150000.00', '240000.00'], array_column($unidades, 'precio_total'));//3 noches: 70 + 100 + 70
    }

    //Sin localidad busca en todos los destinos; con localidad, solo en esa
    public function testLaLocalidadEsOpcional(): void
    {
        $unaLocalidad = $this->localidadNueva();
        $otraLocalidad = $this->localidadNueva();
        $enUna = $this->unidadPublicada($unaLocalidad);
        $enOtra = $this->unidadPublicada($otraLocalidad);

        $this->assertSame([$enUna], $this->idsEncontrados($unaLocalidad, '+10 days', '+12 days'));
        $todas = $this->idsEncontrados(null, '+10 days', '+12 days');
        $this->assertContains($enUna, $todas);
        $this->assertContains($enOtra, $todas);
    }

    //Sin resultados: la lista vacía
    public function testSinResultadosDevuelveLaListaVacia(): void
    {
        $this->assertSame(['unidades' => []], $this->buscar($this->localidadNueva(), '+10 days', '+12 days')->json());
    }

    // ---------- cada condición ----------

    //Solo las publicadas y disponibles: ni en borrador, ni pausadas, ni fuera de servicio
    public function testSoloLasPublicadasYDisponibles(): void
    {
        $localidadId = $this->localidadNueva();
        $publicada = $this->unidadPublicada($localidadId);
        $this->unidadPublicada($localidadId, ['estado_publicacion' => 'borrador']);
        $this->unidadPublicada($localidadId, ['estado_publicacion' => 'pausada']);
        $this->unidadPublicada($localidadId, ['disponible' => 0, 'motivo_no_disponible' => 'Se rompió el techo']);

        $this->assertSame([$publicada], $this->idsEncontrados($localidadId, '+10 days', '+12 days'));
    }

    //La capacidad tiene que alcanzar para todas las personas (justo también sirve)
    public function testLaCapacidadTieneQueAlcanzar(): void
    {
        $localidadId = $this->localidadNueva();
        $paraCuatro = $this->unidadPublicada($localidadId, ['capacidad' => 4]);

        $this->assertSame([$paraCuatro], $this->idsEncontrados($localidadId, '+10 days', '+12 days', 4));
        $this->assertSame([], $this->idsEncontrados($localidadId, '+10 days', '+12 days', 5));
    }

    //Si una sola noche de la estadía no tiene precio, no aparece
    public function testUnaNocheSinPrecioLaDejaAfuera(): void
    {
        $localidadId = $this->localidadNueva();
        $unidadId = $this->unidadPublicada($localidadId);
        $this->pdo()->prepare('UPDATE precio_noche SET vigente_hasta = NOW() WHERE unidad_id = ? AND fecha = ?')->execute([$unidadId, $this->fecha('+11 days')]);

        $this->assertSame([], $this->idsEncontrados($localidadId, '+10 days', '+13 days'));
        $this->assertSame([$unidadId], $this->idsEncontrados($localidadId, '+12 days', '+15 days'));//sin esa noche, sí
    }

    //Una noche bloqueada la deja afuera. El egreso no es una noche: se puede salir el día que empieza un bloqueo,
    //y entrar el día siguiente a la última noche bloqueada.
    public function testLasNochesBloqueadas(): void
    {
        $localidadId = $this->localidadNueva();
        $unidadId = $this->unidadPublicada($localidadId);
        $this->crearBloqueoEnBase($unidadId, $this->fecha('+20 days'), $this->fecha('+25 days'), $this->propietarioId);//noches del 20 al 25

        $this->assertSame([], $this->idsEncontrados($localidadId, '+18 days', '+21 days'));         //toca la noche 20
        $this->assertSame([], $this->idsEncontrados($localidadId, '+25 days', '+27 days'));         //toca la noche 25
        $this->assertSame([$unidadId], $this->idsEncontrados($localidadId, '+17 days', '+20 days')); //sale el 20: no la usa
        $this->assertSame([$unidadId], $this->idsEncontrados($localidadId, '+26 days', '+28 days')); //entra el 26
    }

    //El mínimo de noches de la temporada (RN-09); si toca dos temporadas, se exige el mayor
    public function testElMinimoDeNoches(): void
    {
        $localidadId = $this->localidadNueva();
        $unidadId = $this->unidadPublicada($localidadId);
        $this->crearTemporadaEnBase($unidadId, 'media', $this->fecha('+10 days'), $this->fecha('+19 days'), 3);
        $this->crearTemporadaEnBase($unidadId, 'alta', $this->fecha('+20 days'), $this->fecha('+40 days'), 7);

        $this->assertSame([], $this->idsEncontrados($localidadId, '+10 days', '+12 days'));          //2 noches en media (mínimo 3)
        $this->assertSame([$unidadId], $this->idsEncontrados($localidadId, '+10 days', '+13 days')); //3 noches en media
        $this->assertSame([], $this->idsEncontrados($localidadId, '+17 days', '+22 days'));          //5 noches, toca la alta: mínimo 7
        $this->assertSame([$unidadId], $this->idsEncontrados($localidadId, '+17 days', '+24 days')); //7 noches
        $this->assertSame([$unidadId], $this->idsEncontrados($localidadId, '+5 days', '+6 days'));   //sin temporada: mínimo 1
    }

    //Una localidad inactiva no se puede elegir, y sus unidades no aparecen en la búsqueda general
    public function testUnaLocalidadInactiva(): void
    {
        $inactiva = $this->localidadNueva(false);
        $unidadId = $this->unidadPublicada($inactiva);

        $this->assertSame(422, $this->buscar($inactiva, '+10 days', '+12 days')->codigo);
        $this->assertNotContains($unidadId, $this->idsEncontrados(null, '+10 days', '+12 days'));
    }

    // ---------- validaciones ----------

    //Cada dato inválido se rechaza con un mensaje que dice qué está mal
    public function testValidaLosDatos(): void
    {
        $localidadId = $this->localidadNueva();
        $casos = [
            'ingreso hoy'          => [['ingreso' => $this->fecha()], 'El ingreso tiene que ser de mañana en adelante'],
            'egreso igual'         => [['egreso' => $this->fecha('+10 days')], 'El egreso tiene que ser posterior al ingreso'],
            'más de 60 noches'     => [['egreso' => $this->fecha('+71 days')], 'La estadía puede ser de hasta 60 noches'],
            'sin fechas'           => [['ingreso' => '', 'egreso' => ''], 'Elegí las fechas de ingreso y de egreso'],
            'fecha que no existe'  => [['egreso' => '2027-02-30'], 'Las fechas tienen que tener el formato AAAA-MM-DD'],
            '0 personas'           => [['personas' => 0], 'Indicá cuántas personas van a ser (de 1 a 50)'],
            '51 personas'          => [['personas' => 51], 'Indicá cuántas personas van a ser (de 1 a 50)'],
            'sin personas'         => [['personas' => ''], 'Indicá cuántas personas van a ser (de 1 a 50)'],
            'localidad inexistente' => [['localidad_id' => 999999], 'Elegí una localidad de la lista'],
            'localidad inválida'   => [['localidad_id' => 'abc'], 'Elegí una localidad de la lista'],
        ];

        foreach ($casos as $caso => [$cambios, $mensaje]) {
            $parametros = array_merge([
                'localidad_id' => $localidadId, 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+12 days'), 'personas' => 2,
            ], $cambios);
            $respuesta = $this->cliente()->get('/buscar', $parametros);
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame($mensaje, $respuesta->json()['error'], $caso);
        }
    }

    //Los límites que sí se aceptan: ingreso mañana, 60 noches, 50 personas
    public function testAceptaLosLimites(): void
    {
        $localidadId = $this->localidadNueva();
        $this->assertSame(200, $this->buscar($localidadId, '+1 day', '+61 days', 50)->codigo);
    }
}
