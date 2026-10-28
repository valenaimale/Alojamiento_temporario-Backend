<?php

namespace Tests\Integracion;

//Publicar y pausar una unidad (US3.1): solo con lo que piden las reglas de publicación (precio y foto),
//y solo el propietario.
final class PublicacionTest extends CasoDeIntegracion
{
    //Un propietario con una unidad en ese estado y su sesión iniciada: [cliente, id de la unidad, id del propietario]
    private function propietarioConUnidad(array $cambiosUnidad = []): array
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id'], $cambiosUnidad);
        return [$this->iniciarSesion($propietario['mail']), $unidadId, $propietario['id']];
    }

    //Le da a la unidad lo que necesita para publicarse: precios para los próximos meses y una foto
    private function completarParaPublicar(int $unidadId, int $propietarioId): void
    {
        $this->cargarPreciosParaPublicarEnBase($unidadId, $propietarioId);
        $this->agregarFotoEnBase($unidadId);
    }

    //El estado de publicación guardado en la base
    private function estado(int $unidadId): string
    {
        return $this->pdo()->query("SELECT estado_publicacion FROM unidades WHERE id = $unidadId")->fetchColumn();
    }

    //Con precio y foto, una unidad en borrador se publica
    public function testPublicaUnaUnidadCompleta(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $this->completarParaPublicar($unidadId, $propietarioId);

        $respuesta = $cliente->post('/unidades/publicar', ['unidad_id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['ok' => 'Publicaste la unidad: ya aparece en la búsqueda', 'estado_publicacion' => 'publicada'], $respuesta->json());
        $this->assertSame('publicada', $this->estado($unidadId));
    }

    //Sin precio ni foto no se publica: responde todo lo que le falta y sigue en borrador
    public function testSinPrecioNiFotoNoSePublica(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $respuesta = $cliente->post('/unidades/publicar', ['unidad_id' => $unidadId]);

        $this->assertSame(422, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'error'     => 'Para publicarla te falta completar algunas cosas',
            'faltantes' => [$this->faltaPrecio($this->nochesExigidasParaPublicar()), 'Subí al menos una foto'],
        ], $respuesta->json());
        $this->assertSame('borrador', $this->estado($unidadId));
    }

    //Con precio pero sin foto tampoco (decidido el 28/10)
    public function testSinFotoNoSePublica(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $this->cargarPreciosParaPublicarEnBase($unidadId, $propietarioId);

        $respuesta = $cliente->post('/unidades/publicar', ['unidad_id' => $unidadId]);

        $this->assertSame(422, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['Subí al menos una foto'], $respuesta->json()['faltantes']);
    }

    //Una unidad fuera de servicio por un daño no se publica, aunque tenga todo
    public function testUnaUnidadFueraDeServicioNoSePublica(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad(['disponible' => 0, 'motivo_no_disponible' => 'Se rompió el techo']);
        $this->completarParaPublicar($unidadId, $propietarioId);

        $respuesta = $cliente->post('/unidades/publicar', ['unidad_id' => $unidadId]);

        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('No se puede publicar: la unidad está fuera de servicio', $respuesta->json()['error']);
        $this->assertSame('borrador', $this->estado($unidadId));
    }

    //Publicar una unidad ya publicada responde 200 sin cambiar nada
    public function testPublicarUnaYaPublicada(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad(['estado_publicacion' => 'publicada']);

        $respuesta = $cliente->post('/unidades/publicar', ['unidad_id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('La unidad ya está publicada', $respuesta->json()['ok']);
    }

    //Pausar y volver a publicar
    public function testPausaYVuelveAPublicar(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad(['estado_publicacion' => 'publicada']);
        $this->completarParaPublicar($unidadId, $propietarioId);

        $pausar = $cliente->post('/unidades/pausar', ['unidad_id' => $unidadId]);
        $this->assertSame(200, $pausar->codigo, $pausar->cuerpo);
        $this->assertSame('pausada', $pausar->json()['estado_publicacion']);
        $this->assertSame('pausada', $this->estado($unidadId));

        $otraVez = $cliente->post('/unidades/pausar', ['unidad_id' => $unidadId]);
        $this->assertSame(200, $otraVez->codigo);
        $this->assertSame('La unidad ya está pausada', $otraVez->json()['ok']);

        $publicar = $cliente->post('/unidades/publicar', ['unidad_id' => $unidadId]);
        $this->assertSame(200, $publicar->codigo, $publicar->cuerpo);
        $this->assertSame('publicada', $this->estado($unidadId));
    }

    //Pausar una unidad que nunca se publicó no tiene sentido: 409
    public function testNoSePausaUnaUnidadEnBorrador(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $respuesta = $cliente->post('/unidades/pausar', ['unidad_id' => $unidadId]);

        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('La unidad no está publicada', $respuesta->json()['error']);
        $this->assertSame('borrador', $this->estado($unidadId));
    }

    //Solo el propietario de la unidad: otro propietario 404, el administrador asignado 403, sin sesión 401
    public function testSoloElPropietarioDeLaUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id'], ['estado_publicacion' => 'publicada']);
        $this->completarParaPublicar($unidadId, $propietario['id']);
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $quienes = [
            'otro propietario'          => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404],
            'el administrador asignado' => [$this->iniciarSesion($administrador['mail']), 403],
            'sin sesión'                => [$this->cliente(), 401],
        ];
        foreach ($quienes as $quien => [$cliente, $codigo]) {
            $this->assertSame($codigo, $cliente->post('/unidades/pausar', ['unidad_id' => $unidadId])->codigo, "$quien: pausar");
            $this->assertSame($codigo, $cliente->post('/unidades/publicar', ['unidad_id' => $unidadId])->codigo, "$quien: publicar");
        }
        $this->assertSame('publicada', $this->estado($unidadId));
    }
}
