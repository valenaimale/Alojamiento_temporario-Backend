<?php

namespace Tests\Integracion;

use PDOException;
use Tests\Soporte\ClienteApi;

//Fechas bloqueadas de una unidad (US3.3): listar, bloquear y liberar. Las pueden manejar el propietario
//y el administrador asignado; las noches pasadas no se bloquean ni se liberan.
final class BloqueosTest extends CasoDeIntegracion
{
    //Un propietario con una unidad y su sesión iniciada: [cliente, id de la unidad, el propietario]
    private function propietarioConUnidad(): array
    {
        $propietario = $this->crearPropietarioEnBase(['nombre' => 'Marisa']);
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        return [$this->iniciarSesion($propietario['mail']), $unidadId, $propietario];
    }

    //Los datos de un bloqueo válido, con los cambios que pida el test
    private function bloqueo(int $unidadId, array $cambios = []): array
    {
        return array_merge([
            'unidad_id'   => $unidadId,
            'fecha_desde' => $this->fecha('+10 days'),
            'fecha_hasta' => $this->fecha('+16 days'),
            'motivo'      => 'reserva_externa',
            'detalle'     => 'Reservada por Airbnb',
        ], $cambios);
    }

    //Crea un bloqueo por la API y devuelve su id
    private function crear(ClienteApi $cliente, array $datos): int
    {
        $respuesta = $cliente->post('/unidades/bloqueos/crear', $datos);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['id'];
    }

    //La fila de un bloqueo en la base (false si no existe)
    private function fila(int $bloqueoId): array|false
    {
        return $this->pdo()->query("SELECT fecha_desde, fecha_hasta, motivo, detalle, creado_por FROM bloqueos_calendario WHERE id = $bloqueoId")->fetch();
    }

    // ---------- bloquear ----------

    //El propietario bloquea un rango: queda guardado con el motivo y quién lo cargó
    public function testElPropietarioBloqueaFechas(): void
    {
        [$cliente, $unidadId, $propietario] = $this->propietarioConUnidad();

        $id = $this->crear($cliente, $this->bloqueo($unidadId));

        $this->assertSame([
            'fecha_desde' => $this->fecha('+10 days'),
            'fecha_hasta' => $this->fecha('+16 days'),
            'motivo'      => 'reserva_externa',
            'detalle'     => 'Reservada por Airbnb',
            'creado_por'  => $propietario['id'],
        ], $this->fila($id));
    }

    //El administrador asignado también bloquea, y queda registrado que fue él
    public function testElAdministradorAsignadoBloqueaFechas(): void
    {
        [, $unidadId] = $this->propietarioConUnidad();
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $id = $this->crear($this->iniciarSesion($administrador['mail']), $this->bloqueo($unidadId, ['motivo' => 'mantenimiento', 'detalle' => '']));

        $this->assertSame($administrador['id'], $this->fila($id)['creado_por']);
        $this->assertNull($this->fila($id)['detalle']);//vacío se guarda como NULL
    }

    //Todos los motivos se aceptan; con "otro", el detalle es obligatorio
    public function testLosMotivos(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        foreach (['reserva_externa', 'mantenimiento', 'danio', 'uso_personal', 'otro'] as $i => $motivo) {
            $noche = $this->fecha('+' . ($i + 1) . ' days');//una noche distinta para cada uno, así no se superponen
            $this->crear($cliente, $this->bloqueo($unidadId, ['fecha_desde' => $noche, 'fecha_hasta' => $noche, 'motivo' => $motivo, 'detalle' => 'Pintamos el frente']));
        }

        foreach (['', '   '] as $detalle) {
            $respuesta = $cliente->post('/unidades/bloqueos/crear', $this->bloqueo($unidadId, ['fecha_desde' => $this->fecha('+50 days'), 'fecha_hasta' => $this->fecha('+50 days'), 'motivo' => 'otro', 'detalle' => $detalle]));
            $this->assertSame(422, $respuesta->codigo, "detalle '$detalle'");
            $this->assertSame('Contanos el motivo del bloqueo', $respuesta->json()['error']);
        }
    }

    //La base también exige el detalle con el motivo "otro" (CHECK), por si algún día se inserta sin pasar por la API
    public function testLaBaseExigeElDetalleConOtro(): void
    {
        [, $unidadId, $propietario] = $this->propietarioConUnidad();

        $this->expectException(PDOException::class);
        $this->pdo()->prepare("INSERT INTO bloqueos_calendario (unidad_id, fecha_desde, fecha_hasta, motivo, creado_por) VALUES (?, ?, ?, 'otro', ?)")
            ->execute([$unidadId, $this->fecha('+1 day'), $this->fecha('+1 day'), $propietario['id']]);
    }

    //Cada dato inválido se rechaza con su mensaje, y no se guarda nada
    public function testValidaLosDatos(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $casos = [
            'empieza ayer'        => [['fecha_desde' => $this->fecha('-1 day')], 'Solo podés bloquear noches de hoy en adelante'],
            'fecha que no existe' => [['fecha_hasta' => '2027-02-30'], 'Las fechas tienen que tener el formato AAAA-MM-DD'],
            'al revés'            => [['fecha_desde' => $this->fecha('+20 days'), 'fecha_hasta' => $this->fecha('+19 days')], 'La primera noche no puede ser posterior a la última'],
            'más de 366 noches'   => [['fecha_desde' => $this->fecha('+1 day'), 'fecha_hasta' => $this->fecha('+367 days')], 'Podés bloquear hasta 366 noches de una vez'],
            'sin motivo'          => [['motivo' => ''], 'Elegí el motivo del bloqueo'],
            'motivo inventado'    => [['motivo' => 'vacaciones'], 'Elegí el motivo del bloqueo'],
            'detalle muy largo'   => [['detalle' => str_repeat('a', 256)], 'El detalle puede tener hasta 255 caracteres'],
        ];

        foreach ($casos as $caso => [$cambios, $mensaje]) {
            $respuesta = $cliente->post('/unidades/bloqueos/crear', $this->bloqueo($unidadId, $cambios));
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame($mensaje, $respuesta->json()['error'], $caso);
        }
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM bloqueos_calendario WHERE unidad_id = $unidadId")->fetchColumn());
    }

    //Hoy se puede bloquear, y 366 noches exactas también
    public function testAceptaLosLimites(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $this->crear($cliente, $this->bloqueo($unidadId, ['fecha_desde' => $this->fecha(), 'fecha_hasta' => $this->fecha('+365 days')]));
    }

    //Dos bloqueos no comparten ninguna noche; pegados sí se puede
    public function testNoSePuedenSuperponer(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $this->crear($cliente, $this->bloqueo($unidadId));//noches del +10 al +16

        $respuesta = $cliente->post('/unidades/bloqueos/crear', $this->bloqueo($unidadId, ['fecha_desde' => $this->fecha('+16 days'), 'fecha_hasta' => $this->fecha('+20 days')]));
        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $fechaEnPantalla = fn (string $d) => implode('/', array_reverse(explode('-', $this->fecha($d))));
        $this->assertSame("Se superpone con otro bloqueo, del {$fechaEnPantalla('+10 days')} al {$fechaEnPantalla('+16 days')}", $respuesta->json()['error']);

        $this->crear($cliente, $this->bloqueo($unidadId, ['fecha_desde' => $this->fecha('+17 days'), 'fecha_hasta' => $this->fecha('+20 days')]));
    }

    // ---------- listar ----------

    //Lista los que no terminaron, ordenados por fecha, con el motivo, el detalle y el nombre de quién lo cargó
    public function testListaLosBloqueosQueNoTerminaron(): void
    {
        [$cliente, $unidadId, $propietario] = $this->propietarioConUnidad();
        $this->crearBloqueoEnBase($unidadId, $this->fecha('-10 days'), $this->fecha('-1 day'), $propietario['id']);//ya terminó
        $futuro = $this->crearBloqueoEnBase($unidadId, $this->fecha('+30 days'), $this->fecha('+32 days'), $propietario['id'], 'mantenimiento');
        $enCurso = $this->crearBloqueoEnBase($unidadId, $this->fecha('-2 days'), $this->fecha('+2 days'), $propietario['id']);

        $respuesta = $cliente->get('/unidades/bloqueos', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['bloqueos' => [
            ['id' => $enCurso, 'fecha_desde' => $this->fecha('-2 days'), 'fecha_hasta' => $this->fecha('+2 days'), 'motivo' => 'reserva_externa', 'detalle' => null, 'creado_por' => 'Marisa'],
            ['id' => $futuro, 'fecha_desde' => $this->fecha('+30 days'), 'fecha_hasta' => $this->fecha('+32 days'), 'motivo' => 'mantenimiento', 'detalle' => null, 'creado_por' => 'Marisa'],
        ]], $respuesta->json());
    }

    // ---------- liberar ----------

    //Un bloqueo que todavía no empezó se borra
    public function testLiberarUnBloqueoFuturoLoBorra(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $id = $this->crear($cliente, $this->bloqueo($unidadId));

        $respuesta = $cliente->post('/unidades/bloqueos/liberar', ['unidad_id' => $unidadId, 'bloqueo_id' => $id]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Liberaste esas fechas', $respuesta->json()['ok']);
        $this->assertFalse($this->fila($id));
    }

    //Un bloqueo en curso se recorta hasta ayer: las noches pasadas no se liberan
    public function testLiberarUnBloqueoEnCursoLoRecortaHastaAyer(): void
    {
        [$cliente, $unidadId, $propietario] = $this->propietarioConUnidad();
        $id = $this->crearBloqueoEnBase($unidadId, $this->fecha('-3 days'), $this->fecha('+3 days'), $propietario['id']);

        $respuesta = $cliente->post('/unidades/bloqueos/liberar', ['unidad_id' => $unidadId, 'bloqueo_id' => $id]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Liberamos esas fechas de hoy en adelante', $respuesta->json()['ok']);
        $this->assertSame($this->fecha('-3 days'), $this->fila($id)['fecha_desde']);
        $this->assertSame($this->fecha('-1 day'), $this->fila($id)['fecha_hasta']);
    }

    //Un bloqueo que ya terminó no se libera
    public function testUnBloqueoTerminadoNoSeLibera(): void
    {
        [$cliente, $unidadId, $propietario] = $this->propietarioConUnidad();
        $id = $this->crearBloqueoEnBase($unidadId, $this->fecha('-5 days'), $this->fecha('-1 day'), $propietario['id']);

        $respuesta = $cliente->post('/unidades/bloqueos/liberar', ['unidad_id' => $unidadId, 'bloqueo_id' => $id]);

        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($this->fecha('-1 day'), $this->fila($id)['fecha_hasta']);
    }

    //Un bloqueo de otra unidad (aunque sea del mismo propietario), uno que no existe, o un id inválido
    public function testLiberarUnBloqueoAjenoOInexistente(): void
    {
        [$cliente, $unidadId, $propietario] = $this->propietarioConUnidad();
        $deOtraUnidad = $this->crearBloqueoEnBase($this->crearUnidadEnBase($propietario['id']), $this->fecha('+1 day'), $this->fecha('+2 days'), $propietario['id']);

        foreach ([$deOtraUnidad, 999999] as $id) {
            $respuesta = $cliente->post('/unidades/bloqueos/liberar', ['unidad_id' => $unidadId, 'bloqueo_id' => $id]);
            $this->assertSame(404, $respuesta->codigo, "bloqueo $id");
            $this->assertSame('No encontramos ese bloqueo', $respuesta->json()['error']);
        }
        $this->assertSame(422, $cliente->post('/unidades/bloqueos/liberar', ['unidad_id' => $unidadId, 'bloqueo_id' => 'x'])->codigo);
        $this->assertNotFalse($this->fila($deOtraUnidad));
    }

    // ---------- permisos ----------

    //Nadie más: otro propietario, otro administrador, un huésped y un operador reciben 404; sin sesión, 401
    public function testNadieMasPuedeVerNiBloquear(): void
    {
        [, $unidadId, $propietario] = $this->propietarioConUnidad();
        $bloqueoId = $this->crearBloqueoEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+2 days'), $propietario['id']);

        $quienes = [
            'otro propietario'                      => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404],
            'un administrador que no la administra' => [$this->iniciarSesion($this->crearAdministradorEnBase()['mail']), 404],
            'un huésped'                            => [$this->iniciarSesion($this->crearUsuarioEnBase('huesped')['mail']), 404],
            'un operador'                           => [$this->iniciarSesion($this->crearUsuarioEnBase('operador')['mail']), 404],
            'sin sesión'                            => [$this->cliente(), 401],
        ];
        foreach ($quienes as $quien => [$cliente, $codigo]) {
            $this->assertSame($codigo, $cliente->get('/unidades/bloqueos', ['id' => $unidadId])->codigo, "$quien: listar");
            $this->assertSame($codigo, $cliente->post('/unidades/bloqueos/crear', $this->bloqueo($unidadId))->codigo, "$quien: crear");
            $this->assertSame($codigo, $cliente->post('/unidades/bloqueos/liberar', ['unidad_id' => $unidadId, 'bloqueo_id' => $bloqueoId])->codigo, "$quien: liberar");
        }
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM bloqueos_calendario WHERE unidad_id = $unidadId")->fetchColumn());
    }
}
