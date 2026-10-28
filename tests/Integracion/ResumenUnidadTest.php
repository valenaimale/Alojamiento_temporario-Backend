<?php

namespace Tests\Integracion;

//GET /unidades/resumen (la página central de una unidad).
//Prueba también PermisosUnidad (quién puede ver una unidad) y ReglasPublicacion (qué le falta para publicarse).
final class ResumenUnidadTest extends CasoDeIntegracion
{
    private const FALTA_PRECIO = 'Cargá el precio de al menos una noche, de hoy en adelante';
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
            'faltantes'            => [self::FALTA_PRECIO, self::FALTA_FOTO],
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

    //RN-01: para publicarse necesita al menos una noche con precio vigente, de hoy en adelante
    //(todas las unidades de este test tienen foto, así solo se mira el precio)
    public function testLaReglaDePublicacionMiraLosPreciosVigentesDeHoyEnAdelante(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $cliente = $this->iniciarSesion($propietario['mail']);
        $faltantes = fn (int $id) => $cliente->get('/unidades/resumen', ['id' => $id])->json()['unidad']['faltantes'];
        $unidadConFoto = function () use ($propietario) {
            $id = $this->crearUnidadEnBase($propietario['id']);
            $this->agregarFotoEnBase($id);
            return $id;
        };

        //solo noches pasadas: le falta
        $conPreciosPasados = $unidadConFoto();
        $this->cargarPreciosEnBase($conPreciosPasados, $this->fecha('-10 days'), $this->fecha('-1 day'), 70000, $propietario['id']);
        $this->assertSame([self::FALTA_PRECIO], $faltantes($conPreciosPasados));

        //una noche de hoy: no le falta nada
        $conPrecioHoy = $unidadConFoto();
        $this->cargarPreciosEnBase($conPrecioHoy, $this->fecha(), $this->fecha(), 70000, $propietario['id']);
        $this->assertSame([], $faltantes($conPrecioHoy));

        //un precio que ya no está vigente (se reemplazó y no hay otro): le falta
        $conPrecioCerrado = $unidadConFoto();
        $this->cargarPreciosEnBase($conPrecioCerrado, $this->fecha('+5 days'), $this->fecha('+5 days'), 70000, $propietario['id']);
        $this->pdo()->prepare('UPDATE precio_noche SET vigente_hasta = NOW() WHERE unidad_id = ?')->execute([$conPrecioCerrado]);
        $this->assertSame([self::FALTA_PRECIO], $faltantes($conPrecioCerrado));
    }

    //Para publicarse también necesita al menos una foto (decidido el 28/10)
    public function testLaReglaDePublicacionPideAlMenosUnaFoto(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $this->cargarPreciosEnBase($unidadId, $this->fecha(), $this->fecha('+5 days'), 70000, $propietario['id']);
        $cliente = $this->iniciarSesion($propietario['mail']);

        $this->assertSame([self::FALTA_FOTO], $cliente->get('/unidades/resumen', ['id' => $unidadId])->json()['unidad']['faltantes']);

        $this->agregarFotoEnBase($unidadId);
        $this->assertSame([], $cliente->get('/unidades/resumen', ['id' => $unidadId])->json()['unidad']['faltantes']);
    }
}
