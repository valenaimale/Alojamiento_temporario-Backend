<?php

namespace Tests\Integracion;

//La escena de las pruebas de la reserva grupal (US4.4), compartida por UnidadesGrupalesTest y ReservaGrupalTest:
//un administrador con tres cabañas de 4 personas, de dos propietarias, en una localidad nueva (así la búsqueda no se
//mezcla con las unidades de otros tests), publicadas a $70.000 la noche.
trait EscenaGrupal
{
    //Arma la escena. Devuelve el administrador (con su cliente con la sesión iniciada), las propietarias, la localidad
    //y los ids de las tres cabañas: la 1 y la 2 de Marta, la 3 de Sofía.
    private function escena(): array
    {
        $administrador = $this->crearAdministradorEnBase();
        $propietarias = [$this->crearPropietarioEnBase(['nombre' => 'Marta']), $this->crearPropietarioEnBase(['nombre' => 'Sofía'])];
        $escena = [
            'administrador' => $administrador,
            'propietarias'  => $propietarias,
            'localidad'     => $this->localidadNueva(),
        ];
        $escena['unidades'] = [
            $this->unidadAdministrada($escena, $escena['localidad'], $propietarias[0]['id']),
            $this->unidadAdministrada($escena, $escena['localidad'], $propietarias[0]['id']),
            $this->unidadAdministrada($escena, $escena['localidad'], $propietarias[1]['id']),
        ];
        $escena['cliente'] = $this->iniciarSesion($administrador['mail']);
        return $escena;
    }

    //Una cabaña publicada de 4 personas, con foto y precio, administrada por el administrador de la escena
    private function unidadAdministrada(array $escena, int $localidadId, ?int $propietariaId = null): int
    {
        $propietariaId ??= $escena['propietarias'][0]['id'];
        $unidadId = $this->crearUnidadEnBase($propietariaId, [
            'localidad_id' => $localidadId, 'estado_publicacion' => 'publicada', 'capacidad' => 4, 'nombre' => 'Cabaña ' . uniqid(),
        ]);
        $this->agregarFotoEnBase($unidadId);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+60 days'), 70000, $propietariaId);
        $this->asignarAdministradorEnBase($unidadId, $escena['administrador']['id']);
        return $unidadId;
    }

    //Una localidad nueva, solo para este test (en una provincia propia, como en BusquedaTest). Devuelve su id.
    private function localidadNueva(): int
    {
        $this->pdo()->prepare('INSERT INTO localidades (nombre, provincia, activa) VALUES (?, ?, 1)')
            ->execute(['Localidad ' . uniqid(), 'Zona de Prueba']);
        return (int) $this->pdo()->lastInsertId();
    }

    //Crea por la API la grupal con las tres cabañas de la escena. Devuelve su id.
    private function crearGrupal(array $escena): int
    {
        $respuesta = $escena['cliente']->post('/administrador/grupales/crear', [
            'titulo' => 'Cabañas del grupo', 'unidad_ids' => $escena['unidades'],
        ]);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['id'];
    }

    //Los parámetros de una búsqueda de 3 noches (del +10 al +13) en la localidad de la escena
    private function parametros(array $escena, int $personas): array
    {
        return [
            'localidad_id' => $escena['localidad'], 'ingreso' => $this->fecha('+10 days'),
            'egreso' => $this->fecha('+13 days'), 'personas' => $personas,
        ];
    }
}
