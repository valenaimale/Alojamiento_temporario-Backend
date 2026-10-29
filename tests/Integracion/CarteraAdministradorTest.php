<?php

namespace Tests\Integracion;

//La cartera del administrador (US2.5): GET /administrador/unidades.
final class CarteraAdministradorTest extends CasoDeIntegracion
{
    //Las unidades que administra, ordenadas por nombre, con el propietario (solo el nombre), el estado, la comisión y la foto
    public function testDevuelveSusUnidades(): void
    {
        $propietario = $this->crearPropietarioEnBase(['nombre' => 'Marisa']);
        $administrador = $this->crearAdministradorEnBase();
        $casa = $this->crearUnidadEnBase($propietario['id'], ['nombre' => 'Casa del Bosque', 'estado_publicacion' => 'publicada']);
        $cabania = $this->crearUnidadEnBase($propietario['id'], ['nombre' => 'Cabaña del Mar', 'disponible' => 0, 'motivo_no_disponible' => 'Daño']);
        $this->asignarAdministradorEnBase($casa, $administrador['id'], 15);
        $this->asignarAdministradorEnBase($cabania, $administrador['id'], 17.5);
        $this->agregarFotoEnBase($casa);

        $respuesta = $this->iniciarSesion($administrador['mail'])->get('/administrador/unidades');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['unidades' => [
            [
                'id' => $cabania, 'nombre' => 'Cabaña del Mar', 'localidad' => 'Las Grutas', 'provincia' => 'Río Negro',
                'propietario' => 'Marisa', 'estado_publicacion' => 'borrador', 'disponible' => false,
                'porcentaje_comision' => '17.50', 'foto_principal' => null,
            ],
            [
                'id' => $casa, 'nombre' => 'Casa del Bosque', 'localidad' => 'Las Grutas', 'provincia' => 'Río Negro',
                'propietario' => 'Marisa', 'estado_publicacion' => 'publicada', 'disponible' => true,
                'porcentaje_comision' => '15.00', 'foto_principal' => 'https://res.cloudinary.com/ynclnyuh/image/upload/unidades/prueba.jpg',
            ],
        ]], $respuesta->json());
        $this->assertStringNotContainsString($propietario['mail'], $respuesta->cuerpo);//del propietario, solo el nombre
    }

    //Solo las suyas: nunca las de otro administrador ni las de autogestión
    public function testSoloLasSuyas(): void
    {
        $propietarioId = $this->crearPropietarioEnBase()['id'];
        $administrador = $this->crearAdministradorEnBase();
        $suya = $this->crearUnidadEnBase($propietarioId);
        $this->asignarAdministradorEnBase($suya, $administrador['id']);
        $this->asignarAdministradorEnBase($this->crearUnidadEnBase($propietarioId), $this->crearAdministradorEnBase()['id']);
        $this->crearUnidadEnBase($propietarioId);//de autogestión

        $unidades = $this->iniciarSesion($administrador['mail'])->get('/administrador/unidades')->json()['unidades'];

        $this->assertSame([$suya], array_column($unidades, 'id'));
    }

    //Un administrador sin unidades recibe la lista vacía
    public function testSinUnidadesDevuelveLaListaVacia(): void
    {
        $administrador = $this->crearAdministradorEnBase();

        $this->assertSame(['unidades' => []], $this->iniciarSesion($administrador['mail'])->get('/administrador/unidades')->json());
    }

    //Solo para administradores: un propietario y un operador reciben 403; sin sesión, 401
    public function testSoloParaAdministradores(): void
    {
        $this->assertSame(401, $this->cliente()->get('/administrador/unidades')->codigo);
        $this->assertSame(403, $this->iniciarSesion($this->crearPropietarioEnBase()['mail'])->get('/administrador/unidades')->codigo);
        $this->assertSame(403, $this->iniciarSesion($this->crearUsuarioEnBase('operador')['mail'])->get('/administrador/unidades')->codigo);
    }
}
