<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//Las temporadas de una unidad (US2.7, US2.9): listar, crear, editar y borrar, sin superponerse.
final class TemporadasTest extends CasoDeIntegracion
{
    //Un propietario con una unidad y su sesión iniciada: [cliente, id de la unidad, id del propietario]
    private function propietarioConUnidad(): array
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        return [$this->iniciarSesion($propietario['mail']), $unidadId, $propietario['id']];
    }

    //Los datos de una temporada válida, con los cambios que pida el test
    private function temporada(int $unidadId, array $cambios = []): array
    {
        return array_merge([
            'unidad_id'     => $unidadId,
            'nombre'        => 'alta',
            'fecha_inicio'  => $this->fecha('+10 days'),
            'fecha_fin'     => $this->fecha('+40 days'),
            'minimo_noches' => 7,
        ], $cambios);
    }

    //Crea una temporada por la API y devuelve su id
    private function crear(ClienteApi $cliente, array $datos): int
    {
        $respuesta = $cliente->post('/unidades/temporadas/crear', $datos);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['id'];
    }

    //Una fecha relativa a hoy como la muestra el mensaje de superposición: dd/mm/aaaa
    private function fechaEnPantalla(string $desplazamiento): string
    {
        return implode('/', array_reverse(explode('-', $this->fecha($desplazamiento))));
    }

    // ---------- crear ----------

    //El propietario crea una temporada y queda guardada como la mandó
    public function testElPropietarioCreaUnaTemporada(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $id = $this->crear($cliente, $this->temporada($unidadId));

        $consulta = $this->pdo()->prepare('SELECT unidad_id, nombre, fecha_inicio, fecha_fin, minimo_noches FROM temporadas WHERE id = ?');
        $consulta->execute([$id]);
        $this->assertSame([
            'unidad_id'     => $unidadId,
            'nombre'        => 'alta',
            'fecha_inicio'  => $this->fecha('+10 days'),
            'fecha_fin'     => $this->fecha('+40 days'),
            'minimo_noches' => 7,
        ], $consulta->fetch());
    }

    //Cada dato inválido se rechaza con un mensaje que dice qué está mal
    public function testValidaLosDatos(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $casos = [
            'nombre inexistente'      => [['nombre' => 'altísima'], 'Elegí si es temporada alta, media o baja'],
            'fecha que no existe'     => [['fecha_fin' => '2027-02-30'], 'Las fechas tienen que tener el formato AAAA-MM-DD'],
            'fecha con otro formato'  => [['fecha_inicio' => '10/12/2026'], 'Las fechas tienen que tener el formato AAAA-MM-DD'],
            'inicio después del fin'  => [['fecha_inicio' => $this->fecha('+20 days'), 'fecha_fin' => $this->fecha('+19 days')], 'La fecha de inicio no puede ser posterior a la de fin'],
            'ya terminó'              => [['fecha_inicio' => $this->fecha('-10 days'), 'fecha_fin' => $this->fecha('-1 day')], 'La temporada tiene que terminar hoy o más adelante'],
            'más de 366 noches'       => [['fecha_inicio' => $this->fecha('+1 day'), 'fecha_fin' => $this->fecha('+367 days')], 'Una temporada puede durar hasta 366 noches'],
            'mínimo 0'                => [['minimo_noches' => 0], 'El mínimo de noches tiene que ser un número de 1 a 60'],
            'mínimo 61'               => [['minimo_noches' => 61], 'El mínimo de noches tiene que ser un número de 1 a 60'],
            'mínimo que no es número' => [['minimo_noches' => 'siete'], 'El mínimo de noches tiene que ser un número de 1 a 60'],
        ];

        foreach ($casos as $caso => [$cambios, $mensaje]) {
            $respuesta = $cliente->post('/unidades/temporadas/crear', $this->temporada($unidadId, $cambios));
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame($mensaje, $respuesta->json()['error'], $caso);
        }
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM temporadas WHERE unidad_id = $unidadId")->fetchColumn());
    }

    //Los límites que sí se aceptan: 366 noches exactas, mínimo 1 y 60, y una que empezó antes de hoy y termina hoy
    public function testAceptaLosLimites(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $this->crear($cliente, $this->temporada($unidadId, ['fecha_inicio' => $this->fecha('-5 days'), 'fecha_fin' => $this->fecha(), 'minimo_noches' => 1]));
        $this->crear($cliente, $this->temporada($unidadId, ['fecha_inicio' => $this->fecha('+1 day'), 'fecha_fin' => $this->fecha('+366 days'), 'minimo_noches' => 60]));
    }

    //Dos temporadas de la misma unidad no se superponen: ni por una sola noche
    public function testNoSePuedenSuperponer(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $this->crear($cliente, $this->temporada($unidadId));//noches del +10 al +40
        $mensaje = 'Se superpone con la temporada alta del ' . $this->fechaEnPantalla('+10 days') . ' al ' . $this->fechaEnPantalla('+40 days');

        $casos = [
            'empieza la última noche de la otra' => ['+40 days', '+50 days'],
            'termina la primera noche de la otra' => ['+1 day', '+10 days'],
            'queda adentro de la otra'           => ['+20 days', '+25 days'],
            'envuelve a la otra'                 => ['+5 days', '+45 days'],
        ];
        foreach ($casos as $caso => [$inicio, $fin]) {
            $respuesta = $cliente->post('/unidades/temporadas/crear', $this->temporada($unidadId, [
                'nombre' => 'media', 'fecha_inicio' => $this->fecha($inicio), 'fecha_fin' => $this->fecha($fin),
            ]));
            $this->assertSame(409, $respuesta->codigo, $caso);
            $this->assertSame($mensaje, $respuesta->json()['error'], $caso);
        }
    }

    //Una temporada puede empezar la noche siguiente a la última de otra; y otra unidad puede usar las mismas fechas
    public function testPegadaAOtraOEnOtraUnidadSePuede(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $this->crear($cliente, $this->temporada($unidadId));//noches del +10 al +40

        $this->crear($cliente, $this->temporada($unidadId, ['nombre' => 'media', 'fecha_inicio' => $this->fecha('+41 days'), 'fecha_fin' => $this->fecha('+60 days')]));
        $this->crear($cliente, $this->temporada($unidadId, ['nombre' => 'baja', 'fecha_inicio' => $this->fecha('+1 day'), 'fecha_fin' => $this->fecha('+9 days')]));

        $otraUnidad = $this->crearUnidadEnBase($propietarioId);
        $this->crear($cliente, $this->temporada($otraUnidad));
    }

    // ---------- listar ----------

    //Lista las temporadas que no terminaron, ordenadas por fecha, con los números como números
    public function testListaLasTemporadasQueNoTerminaron(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $this->crearTemporadaEnBase($unidadId, 'baja', $this->fecha('-30 days'), $this->fecha('-1 day'), 2);//ya terminó
        $media = $this->crearTemporadaEnBase($unidadId, 'media', $this->fecha('+50 days'), $this->fecha('+60 days'), 3);
        $alta = $this->crearTemporadaEnBase($unidadId, 'alta', $this->fecha('-3 days'), $this->fecha('+49 days'), 7);//en curso

        $respuesta = $cliente->get('/unidades/temporadas', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            ['id' => $alta, 'nombre' => 'alta', 'fecha_inicio' => $this->fecha('-3 days'), 'fecha_fin' => $this->fecha('+49 days'), 'minimo_noches' => 7],
            ['id' => $media, 'nombre' => 'media', 'fecha_inicio' => $this->fecha('+50 days'), 'fecha_fin' => $this->fecha('+60 days'), 'minimo_noches' => 3],
        ], $respuesta->json()['temporadas']);
    }

    //Una unidad sin temporadas devuelve la lista vacía (las temporadas son opcionales)
    public function testSinTemporadasDevuelveLaListaVacia(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $this->assertSame(['temporadas' => []], $cliente->get('/unidades/temporadas', ['id' => $unidadId])->json());
    }

    // ---------- editar ----------

    //Editar el mínimo sin mover las fechas no choca consigo misma
    public function testEditarNoSeComparaConsigoMisma(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $id = $this->crear($cliente, $this->temporada($unidadId));

        $respuesta = $cliente->post('/unidades/temporadas/editar', $this->temporada($unidadId, ['temporada_id' => $id, 'minimo_noches' => 5]));

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(5, $this->pdo()->query("SELECT minimo_noches FROM temporadas WHERE id = $id")->fetchColumn());
    }

    //Editar las fechas para que pisen otra temporada da 409 y no cambia nada
    public function testEditarParaPisarOtraDa409(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $this->crear($cliente, $this->temporada($unidadId));//+10 al +40
        $baja = $this->crear($cliente, $this->temporada($unidadId, ['nombre' => 'baja', 'fecha_inicio' => $this->fecha('+41 days'), 'fecha_fin' => $this->fecha('+60 days')]));

        $respuesta = $cliente->post('/unidades/temporadas/editar', $this->temporada($unidadId, [
            'temporada_id' => $baja, 'nombre' => 'baja', 'fecha_inicio' => $this->fecha('+35 days'), 'fecha_fin' => $this->fecha('+60 days'),
        ]));

        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($this->fecha('+41 days'), $this->pdo()->query("SELECT fecha_inicio FROM temporadas WHERE id = $baja")->fetchColumn());
    }

    //Una temporada que ya terminó no se edita
    public function testUnaTemporadaTerminadaNoSeEdita(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $pasada = $this->crearTemporadaEnBase($unidadId, 'baja', $this->fecha('-30 days'), $this->fecha('-1 day'));

        $respuesta = $cliente->post('/unidades/temporadas/editar', $this->temporada($unidadId, ['temporada_id' => $pasada]));

        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Esa temporada ya terminó: no se puede editar', $respuesta->json()['error']);
    }

    //Una temporada en curso se edita, aunque su inicio ya haya pasado
    public function testUnaTemporadaEnCursoSeEdita(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $enCurso = $this->crearTemporadaEnBase($unidadId, 'alta', $this->fecha('-5 days'), $this->fecha('+5 days'), 7);

        $respuesta = $cliente->post('/unidades/temporadas/editar', $this->temporada($unidadId, [
            'temporada_id' => $enCurso, 'fecha_inicio' => $this->fecha('-5 days'), 'fecha_fin' => $this->fecha('+10 days'),
        ]));

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
    }

    //Una temporada de otra unidad (aunque sea del mismo propietario), una que no existe o un id inválido
    public function testEditarUnaTemporadaAjenaOInexistente(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $deOtraUnidad = $this->crearTemporadaEnBase($this->crearUnidadEnBase($propietarioId), 'alta', $this->fecha('+1 day'), $this->fecha('+5 days'));

        foreach ([$deOtraUnidad, 999999] as $id) {
            $respuesta = $cliente->post('/unidades/temporadas/editar', $this->temporada($unidadId, ['temporada_id' => $id]));
            $this->assertSame(404, $respuesta->codigo, "temporada $id");
            $this->assertSame('No encontramos esa temporada', $respuesta->json()['error']);
        }

        $respuesta = $cliente->post('/unidades/temporadas/editar', $this->temporada($unidadId, ['temporada_id' => 'abc']));
        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('Temporada inválida', $respuesta->json()['error']);
    }

    // ---------- borrar ----------

    //El propietario borra una temporada: desaparece de la base
    public function testElPropietarioBorraUnaTemporada(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $id = $this->crear($cliente, $this->temporada($unidadId));

        $respuesta = $cliente->post('/unidades/temporadas/borrar', ['unidad_id' => $unidadId, 'temporada_id' => $id]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertFalse($this->pdo()->query("SELECT 1 FROM temporadas WHERE id = $id")->fetchColumn());
    }

    //No se puede borrar la temporada de otra unidad pasando el id de una unidad propia
    public function testBorrarUnaTemporadaDeOtraUnidadDa404(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $ajena = $this->crearTemporadaEnBase($this->crearUnidadEnBase($this->crearPropietarioEnBase()['id']), 'alta', $this->fecha('+1 day'), $this->fecha('+5 days'));

        $respuesta = $cliente->post('/unidades/temporadas/borrar', ['unidad_id' => $unidadId, 'temporada_id' => $ajena]);

        $this->assertSame(404, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(1, $this->pdo()->query("SELECT 1 FROM temporadas WHERE id = $ajena")->fetchColumn());
    }

    // ---------- permisos ----------

    //Solo el propietario de la unidad: otro propietario y el administrador asignado reciben 404 o 403, y sin sesión 401
    public function testSoloElPropietarioDeLaUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);
        $temporadaId = $this->crearTemporadaEnBase($unidadId, 'alta', $this->fecha('+1 day'), $this->fecha('+5 days'));

        $pedidos = [
            fn (ClienteApi $c) => $c->get('/unidades/temporadas', ['id' => $unidadId]),
            fn (ClienteApi $c) => $c->post('/unidades/temporadas/crear', $this->temporada($unidadId)),
            fn (ClienteApi $c) => $c->post('/unidades/temporadas/editar', $this->temporada($unidadId, ['temporada_id' => $temporadaId])),
            fn (ClienteApi $c) => $c->post('/unidades/temporadas/borrar', ['unidad_id' => $unidadId, 'temporada_id' => $temporadaId]),
        ];
        $quienes = [
            'otro propietario'          => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404],
            'el administrador asignado' => [$this->iniciarSesion($administrador['mail']), 403],//no es propietario
            'sin sesión'                => [$this->cliente(), 401],
        ];

        foreach ($quienes as $quien => [$cliente, $codigo]) {
            foreach ($pedidos as $i => $pedido) {
                $this->assertSame($codigo, $pedido($cliente)->codigo, "$quien, pedido $i");
            }
        }
        $this->assertSame(1, $this->pdo()->query("SELECT COUNT(*) FROM temporadas WHERE unidad_id = $unidadId")->fetchColumn());
    }
}
