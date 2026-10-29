<?php

namespace Tests\Integracion;

//GET /unidades/resumen (la página central de una unidad).
//Prueba también PermisosUnidad (quién puede ver una unidad) y ReglasPublicacion (qué le falta para publicarse).
final class ResumenUnidadTest extends CasoDeIntegracion
{
    private const FALTA_FOTO = 'Subí al menos una foto';

    //El propietario ve el resumen completo de su unidad
    public function testElPropietarioVeElResumenDeSuUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id'], ['nombre' => 'Cabaña del Mar']);

        $respuesta = $this->iniciarSesion($propietario['mail'])->get('/unidades/resumen', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'id'                   => $unidadId,
            'nombre'               => 'Cabaña del Mar',
            'localidad'            => 'Las Grutas',
            'provincia'            => 'Río Negro',
            'estado_publicacion'   => 'borrador',
            'disponible'           => true,
            'motivo_no_disponible' => null,
            'es_propietario'       => true,
            'faltantes'            => [$this->faltaPrecio($this->nochesExigidasParaPublicar()), self::FALTA_FOTO],
            'precios_hasta'        => null,
        ], $respuesta->json()['unidad']);
    }

    //El administrador asignado la ve, con es_propietario en false
    public function testElAdministradorAsignadoLaVeSinSerPropietario(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $administrador = $this->crearAdministradorEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $respuesta = $this->iniciarSesion($administrador['mail'])->get('/unidades/resumen', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertFalse($respuesta->json()['unidad']['es_propietario']);
    }

    //404 y no 403: nadie puede averiguar qué unidades existen probando números
    public function testNadieMasLaPuedeVer(): void
    {
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id']);
        $otrosUsuarios = [
            'otro propietario'                    => $this->crearPropietarioEnBase(),
            'un administrador que no la administra' => $this->crearAdministradorEnBase(),
            'un huésped'                          => $this->crearUsuarioEnBase('huesped'),
            'un operador'                         => $this->crearUsuarioEnBase('operador'),
        ];

        foreach ($otrosUsuarios as $quien => $usuario) {
            $respuesta = $this->iniciarSesion($usuario['mail'])->get('/unidades/resumen', ['id' => $unidadId]);
            $this->assertSame(404, $respuesta->codigo, $quien);
            $this->assertSame('No encontramos esa unidad', $respuesta->json()['error'], $quien);
        }
    }

    //Sin sesión responde 401
    public function testSinSesionResponde401(): void
    {
        $this->assertSame(401, $this->cliente()->get('/unidades/resumen', ['id' => 1])->codigo);
    }

    //Una unidad que no existe da 404; un id que no es un entero positivo, 422
    public function testUnaUnidadQueNoExisteOUnIdInvalido(): void
    {
        $cliente = $this->iniciarSesion($this->crearPropietarioEnBase()['mail']);

        $this->assertSame(404, $cliente->get('/unidades/resumen', ['id' => 999999])->codigo);
        foreach (['', 'abc', '0', '-3', '1.5'] as $id) {
            $respuesta = $cliente->get('/unidades/resumen', ['id' => $id]);
            $this->assertSame(422, $respuesta->codigo, "id '$id'");
            $this->assertSame('Unidad inválida', $respuesta->json()['error']);
        }
    }

    //Precios (decidido el 29/10): cada noche desde mañana hasta dentro de 6 meses tiene precio o está bloqueada.
    //Todas las unidades de este test tienen foto, así solo se mira el precio.
    public function testLaReglaDePublicacionPideSeisMesesDePrecios(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $cliente = $this->iniciarSesion($propietario['mail']);
        $faltantes = fn (int $id) => $cliente->get('/unidades/resumen', ['id' => $id])->json()['unidad']['faltantes'];
        $unidadConFoto = function () use ($propietario) {
            $id = $this->crearUnidadEnBase($propietario['id']);
            $this->agregarFotoEnBase($id);
            return $id;
        };
        $todas = $this->nochesExigidasParaPublicar();

        //solo noches pasadas y la de hoy: le faltan todas (se exige desde mañana)
        $sinFuturo = $unidadConFoto();
        $this->cargarPreciosEnBase($sinFuturo, $this->fecha('-10 days'), $this->fecha(), 70000, $propietario['id']);
        $this->assertSame([$this->faltaPrecio($todas)], $faltantes($sinFuturo));

        //los 6 meses completos: no le falta nada
        $completa = $unidadConFoto();
        $this->cargarPreciosParaPublicarEnBase($completa, $propietario['id']);
        $this->assertSame([], $faltantes($completa));

        //tres noches sin precio en el medio: le faltan 3
        $conHueco = $unidadConFoto();
        $this->cargarPreciosEnBase($conHueco, $this->fecha('+1 day'), $this->fecha('+9 days'), 70000, $propietario['id']);
        $this->cargarPreciosEnBase($conHueco, $this->fecha('+13 days'), $this->fecha('+7 months'), 70000, $propietario['id']);
        $this->assertSame([$this->faltaPrecio(3)], $faltantes($conHueco));

        //esas tres noches bloqueadas: ya no le falta nada (una noche bloqueada no necesita precio)
        $this->crearBloqueoEnBase($conHueco, $this->fecha('+10 days'), $this->fecha('+12 days'), $propietario['id'], 'uso_personal');
        $this->assertSame([], $faltantes($conHueco));

        //un precio que ya no está vigente (se reemplazó y no hay otro) no cuenta: le falta 1
        $conPrecioCerrado = $unidadConFoto();
        $this->cargarPreciosParaPublicarEnBase($conPrecioCerrado, $propietario['id']);
        $this->pdo()->prepare('UPDATE precio_noche SET vigente_hasta = NOW() WHERE unidad_id = ? AND fecha = ?')
            ->execute([$conPrecioCerrado, $this->fecha('+5 days')]);
        $this->assertSame([$this->faltaPrecio(1)], $faltantes($conPrecioCerrado));
    }

    //precios_hasta: la última noche con precio (o bloqueada) sin cortes desde mañana; null si mañana no tiene
    public function testPreciosHastaEsElPrimerCorteDesdeManiana(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $cliente = $this->iniciarSesion($propietario['mail']);
        $preciosHasta = fn (int $id) => $cliente->get('/unidades/resumen', ['id' => $id])->json()['unidad']['precios_hasta'];

        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+9 days'), 70000, $propietario['id']);
        $this->crearBloqueoEnBase($unidadId, $this->fecha('+10 days'), $this->fecha('+12 days'), $propietario['id']);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+13 days'), $this->fecha('+20 days'), 70000, $propietario['id']);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+30 days'), $this->fecha('+40 days'), 70000, $propietario['id']);//después de un hueco: no cuenta
        $this->assertSame($this->fecha('+20 days'), $preciosHasta($unidadId));

        $soloHoy = $this->crearUnidadEnBase($propietario['id']);
        $this->cargarPreciosEnBase($soloHoy, $this->fecha(), $this->fecha(), 70000, $propietario['id']);
        $this->assertNull($preciosHasta($soloHoy));
    }

    //Para publicarse también necesita al menos una foto (decidido el 28/10)
    public function testLaReglaDePublicacionPideAlMenosUnaFoto(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $this->cargarPreciosParaPublicarEnBase($unidadId, $propietario['id']);
        $cliente = $this->iniciarSesion($propietario['mail']);

        $this->assertSame([self::FALTA_FOTO], $cliente->get('/unidades/resumen', ['id' => $unidadId])->json()['unidad']['faltantes']);

        $this->agregarFotoEnBase($unidadId);
        $this->assertSame([], $cliente->get('/unidades/resumen', ['id' => $unidadId])->json()['unidad']['faltantes']);
    }
}
