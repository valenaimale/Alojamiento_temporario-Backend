<?php

namespace Tests\Integracion;

//Alta y datos de una unidad (US2.1) y su depósito de garantía (US2.6):
//POST /unidades/crear, GET /unidades/mias, GET /unidades/datos, POST /unidades/editar y POST /unidades/deposito.
final class UnidadesTest extends CasoDeIntegracion
{
    private const FALTA_PRECIO = 'Cargá el precio de al menos una noche, de hoy en adelante';
    private const FALTA_FOTO = 'Subí al menos una foto';
    private const FOTO = 'https://res.cloudinary.com/ynclnyuh/image/upload/v1/unidades/';

    //Datos válidos para crear o editar una unidad, con los cambios que pida cada test
    private function datosValidos(array $cambios = []): array
    {
        return array_merge([
            'nombre'          => 'Cabaña del Mar',
            'localidad_id'    => 1,
            'direccion'       => 'Costanera 123',
            'capacidad'       => 4,
            'caracteristicas' => 'Dos dormitorios, parrilla y vista al mar',
        ], $cambios);
    }

    //La fila de la unidad en la base, para revisar lo que quedó guardado
    private function filaUnidad(int $id): array
    {
        $consulta = $this->pdo()->prepare('SELECT * FROM unidades WHERE id = ?');
        $consulta->execute([$id]);
        return $consulta->fetch();
    }

    //Una foto de la unidad directo en la base, con una URL distinta por orden (foto-0.jpg, foto-1.jpg...).
    //No usa agregarFotoEnBase de CasoDeIntegracion porque esa repite la URL, y acá hay que distinguir cuál es la principal.
    private function agregarFotoConUrlPropia(int $unidadId, int $orden): void
    {
        $this->pdo()->prepare('INSERT INTO fotos_unidad (unidad_id, url, orden) VALUES (?, ?, ?)')
            ->execute([$unidadId, self::FOTO . "foto-$orden.jpg", $orden]);
    }

    //Una localidad dada de baja (activa = 0). Devuelve su id.
    private function crearLocalidadInactivaEnBase(): int
    {
        $this->pdo()->prepare('INSERT INTO localidades (nombre, provincia, activa) VALUES (?, ?, 0)')
            ->execute(['Dada de baja ' . uniqid(), 'Río Negro']);
        return (int) $this->pdo()->lastInsertId();
    }

    // ---------- Crear ----------

    //El propietario la crea en borrador, a su nombre aunque el cuerpo mande otro propietario
    public function testElPropietarioDaDeAltaUnaUnidadEnBorrador(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $otro = $this->crearPropietarioEnBase();

        $respuesta = $this->iniciarSesion($propietario['mail'])->post('/unidades/crear', $this->datosValidos([
            'nombre'            => '  Cabaña del Mar  ',
            'capacidad'         => '4',
            'deposito_garantia' => 15000.5,
            'propietario_id'    => $otro['id'],
        ]));

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Diste de alta tu unidad', $respuesta->json()['ok']);
        $unidad = $this->filaUnidad($respuesta->json()['id']);
        $this->assertSame($propietario['id'], (int) $unidad['propietario_id']);
        $this->assertSame('borrador', $unidad['estado_publicacion']);
        $this->assertSame('Cabaña del Mar', $unidad['nombre']);
        $this->assertSame(4, (int) $unidad['capacidad']);
        $this->assertSame('15000.50', $unidad['deposito_garantia']);
        $this->assertNull($unidad['operador_id']);
    }

    //La seña y la política se copian de parametros: si el backoffice cambia el parámetro, la unidad usa el valor de ese momento
    public function testCopiaLaSenaYLaPoliticaDeParametros(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $cliente = $this->iniciarSesion($propietario['mail']);
        $cambiar = $this->pdo()->prepare('UPDATE parametros SET valor = ? WHERE clave = ?');
        $valores = [
            'sena_defecto'                 => ['10', '20'],
            'politica_defecto_mas_de_30'   => ['25', '30'],
            'politica_defecto_de_15_a_30'  => ['55', '50'],
            'politica_defecto_menos_de_15' => ['80', '70'],
        ];

        foreach ($valores as $clave => [$paraLaPrueba]) {
            $cambiar->execute([$paraLaPrueba, $clave]);
        }
        try {
            $respuesta = $cliente->post('/unidades/crear', $this->datosValidos());
        } finally {
            foreach ($valores as $clave => [, $original]) {//los demás tests esperan los valores de la migración
                $cambiar->execute([$original, $clave]);
            }
        }

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $unidad = $this->filaUnidad($respuesta->json()['id']);
        $this->assertSame('10.00', $unidad['porcentaje_sena']);
        $this->assertSame('25.00', $unidad['retencion_mas_de_30']);
        $this->assertSame('55.00', $unidad['retencion_de_15_a_30']);
        $this->assertSame('80.00', $unidad['retencion_menos_de_15']);
    }

    //Sin características ni depósito: quedan en NULL
    public function testLasCaracteristicasYElDepositoSonOpcionales(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $datos = $this->datosValidos(['caracteristicas' => '   ', 'deposito_garantia' => '']);

        $respuesta = $this->iniciarSesion($propietario['mail'])->post('/unidades/crear', $datos);

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $unidad = $this->filaUnidad($respuesta->json()['id']);
        $this->assertNull($unidad['caracteristicas']);
        $this->assertNull($unidad['deposito_garantia']);
    }

    //Cada dato inválido responde 422 con su mensaje y no guarda nada
    public function testValidaLosDatosDeLaUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $cliente = $this->iniciarSesion($propietario['mail']);
        $nombre = 'El nombre tiene que tener entre 3 y 100 caracteres';
        $localidad = 'Elegí una localidad de la lista';
        $direccion = 'La dirección tiene que tener entre 5 y 200 caracteres';
        $capacidad = 'La capacidad tiene que ser de 1 a 50 personas';
        $deposito = 'El depósito tiene que ser un monto en pesos, con hasta 2 decimales';
        $casos = [
            'sin nombre'                 => [['nombre' => null], $nombre],
            'nombre corto'               => [['nombre' => ' ab '], $nombre],
            'nombre largo'               => [['nombre' => str_repeat('ñ', 101)], $nombre],
            'localidad inexistente'      => [['localidad_id' => 999999], $localidad],
            'localidad inactiva'         => [['localidad_id' => $this->crearLocalidadInactivaEnBase()], $localidad],
            'localidad que no es número' => [['localidad_id' => 'abc'], $localidad],
            'dirección corta'            => [['direccion' => 'Mar'], $direccion],
            'dirección larga'            => [['direccion' => str_repeat('a', 201)], $direccion],
            'capacidad 0'                => [['capacidad' => 0], $capacidad],
            'capacidad 51'               => [['capacidad' => 51], $capacidad],
            'capacidad abc'              => [['capacidad' => 'abc'], $capacidad],
            'capacidad con decimales'    => [['capacidad' => '2.5'], $capacidad],
            'capacidad true'             => [['capacidad' => true], $capacidad],
            'características largas'     => [['caracteristicas' => str_repeat('a', 2001)], 'Las características pueden tener hasta 2000 caracteres'],
            'depósito negativo'          => [['deposito_garantia' => -1], $deposito],
            'depósito con 3 decimales'   => [['deposito_garantia' => '100.123'], $deposito],
            'depósito que no es número'  => [['deposito_garantia' => 'mil'], $deposito],
            'depósito demasiado grande'  => [['deposito_garantia' => '12345678901'], $deposito],
        ];

        foreach ($casos as $caso => [$cambios, $mensaje]) {
            $respuesta = $cliente->post('/unidades/crear', $this->datosValidos($cambios));
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame($mensaje, $respuesta->json()['error'], $caso);
        }
        $consulta = $this->pdo()->prepare('SELECT COUNT(*) FROM unidades WHERE propietario_id = ?');
        $consulta->execute([$propietario['id']]);
        $this->assertSame(0, (int) $consulta->fetchColumn());
    }

    //Los límites justos se aceptan: nombre de 3 y de 100, capacidad 1 y 50, características de 2000
    public function testAceptaLosLimitesJustos(): void
    {
        $cliente = $this->iniciarSesion($this->crearPropietarioEnBase()['mail']);
        $casos = [
            ['nombre' => 'Ñam', 'capacidad' => 1],
            ['nombre' => str_repeat('ñ', 100), 'capacidad' => 50, 'caracteristicas' => str_repeat('a', 2000)],
            ['deposito_garantia' => '9999999999.99'],
            ['deposito_garantia' => 0],
        ];

        foreach ($casos as $cambios) {
            $respuesta = $cliente->post('/unidades/crear', $this->datosValidos($cambios));
            $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        }
    }

    //Sin sesión 401; un huésped, un administrador y un operador no pueden dar de alta unidades (403)
    public function testSoloElPropietarioPuedeDarDeAlta(): void
    {
        $this->assertSame(401, $this->cliente()->post('/unidades/crear', $this->datosValidos())->codigo);

        $usuarios = [
            'un huésped'        => $this->crearUsuarioEnBase('huesped'),
            'un administrador'  => $this->crearAdministradorEnBase(),
            'un operador'       => $this->crearUsuarioEnBase('operador'),
        ];
        foreach ($usuarios as $quien => $usuario) {
            $respuesta = $this->iniciarSesion($usuario['mail'])->post('/unidades/crear', $this->datosValidos());
            $this->assertSame(403, $respuesta->codigo, $quien);
        }
    }

    // ---------- Mis unidades ----------

    //Solo las suyas, de la más nueva a la más vieja, con foto principal, administrador, depósito y faltantes
    public function testMisUnidadesMuestraSoloLasDelPropietario(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $administrador = $this->crearAdministradorEnBase(['nombre' => 'Cacho']);
        $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id'], ['nombre' => 'De otro propietario']);

        $autogestionada = $this->crearUnidadEnBase($propietario['id'], [
            'nombre'            => 'Departamento Costanera',
            'deposito_garantia' => 15000,
        ]);
        $administrada = $this->crearUnidadEnBase($propietario['id'], [
            'nombre'             => 'Casa del Bosque',
            'localidad_id'       => $this->idLocalidad('Mar de las Pampas'),
            'estado_publicacion' => 'publicada',
            'disponible'         => 0,
        ]);
        $this->asignarAdministradorEnBase($administrada, $administrador['id'], 15);
        $this->cargarPreciosEnBase($administrada, $this->fecha(), $this->fecha('+5 days'), 70000, $propietario['id']);
        $this->agregarFotoConUrlPropia($administrada, 0);
        $this->agregarFotoConUrlPropia($administrada, 1);

        $respuesta = $this->iniciarSesion($propietario['mail'])->get('/unidades/mias');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            [
                'id'                 => $administrada,
                'nombre'             => 'Casa del Bosque',
                'localidad'          => 'Mar de las Pampas',
                'provincia'          => 'Buenos Aires',
                'estado_publicacion' => 'publicada',
                'disponible'         => false,
                'foto_principal'     => self::FOTO . 'foto-0.jpg',
                'administrador'      => ['nombre' => 'Cacho', 'porcentaje_comision' => '15.00'],
                'deposito_garantia'  => null,
                'faltantes'          => [],
            ],
            [
                'id'                 => $autogestionada,
                'nombre'             => 'Departamento Costanera',
                'localidad'          => 'Las Grutas',
                'provincia'          => 'Río Negro',
                'estado_publicacion' => 'borrador',
                'disponible'         => true,
                'foto_principal'     => null,
                'administrador'      => null,
                'deposito_garantia'  => '15000.00',
                'faltantes'          => [self::FALTA_PRECIO, self::FALTA_FOTO],
            ],
        ], $respuesta->json()['unidades']);
    }

    //Un propietario sin unidades recibe la lista vacía
    public function testMisUnidadesSinUnidades(): void
    {
        $respuesta = $this->iniciarSesion($this->crearPropietarioEnBase()['mail'])->get('/unidades/mias');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['unidades' => []], $respuesta->json());
    }

    //Sin sesión 401; el administrador no es propietario (403)
    public function testMisUnidadesEsSoloDelPropietario(): void
    {
        $this->assertSame(401, $this->cliente()->get('/unidades/mias')->codigo);
        $this->assertSame(403, $this->iniciarSesion($this->crearAdministradorEnBase()['mail'])->get('/unidades/mias')->codigo);
    }

    //El id de una localidad de la carga inicial
    private function idLocalidad(string $nombre): int
    {
        $consulta = $this->pdo()->prepare('SELECT id FROM localidades WHERE nombre = ?');
        $consulta->execute([$nombre]);
        return (int) $consulta->fetchColumn();
    }

    // ---------- Ver los datos ----------

    //El propietario ve todo lo editable y las fotos ordenadas
    public function testElPropietarioVeLosDatosDeSuUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id'], [
            'nombre'            => 'Cabaña del Mar',
            'caracteristicas'   => 'Con parrilla',
            'deposito_garantia' => 15000,
        ]);
        $this->agregarFotoConUrlPropia($unidadId, 1);
        $this->agregarFotoConUrlPropia($unidadId, 0);

        $respuesta = $this->iniciarSesion($propietario['mail'])->get('/unidades/datos', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $unidad = $respuesta->json()['unidad'];
        $fotos = $unidad['fotos'];
        unset($unidad['fotos']);
        $this->assertSame([
            'id'                 => $unidadId,
            'nombre'             => 'Cabaña del Mar',
            'localidad_id'       => 1,
            'direccion'          => 'Costanera 123',
            'capacidad'          => 4,
            'caracteristicas'    => 'Con parrilla',
            'deposito_garantia'  => '15000.00',
            'estado_publicacion' => 'borrador',
            'es_propietario'     => true,
        ], $unidad);
        $this->assertSame([0, 1], array_column($fotos, 'orden'));
        $this->assertSame(self::FOTO . 'foto-0.jpg', $fotos[0]['url']);
        $this->assertIsInt($fotos[0]['id']);
    }

    //El administrador asignado la ve, con es_propietario en false
    public function testElAdministradorAsignadoVeLosDatos(): void
    {
        $administrador = $this->crearAdministradorEnBase();
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id']);
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $respuesta = $this->iniciarSesion($administrador['mail'])->get('/unidades/datos', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertFalse($respuesta->json()['unidad']['es_propietario']);
        $this->assertSame([], $respuesta->json()['unidad']['fotos']);
    }

    //Otro propietario y un administrador que no la administra reciben 404; sin sesión, 401
    public function testNadieMasVeLosDatos(): void
    {
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id']);

        foreach ([$this->crearPropietarioEnBase(), $this->crearAdministradorEnBase()] as $usuario) {
            $respuesta = $this->iniciarSesion($usuario['mail'])->get('/unidades/datos', ['id' => $unidadId]);
            $this->assertSame(404, $respuesta->codigo);
            $this->assertSame('No encontramos esa unidad', $respuesta->json()['error']);
        }
        $this->assertSame(401, $this->cliente()->get('/unidades/datos', ['id' => $unidadId])->codigo);
    }

    // ---------- Editar ----------

    //El propietario cambia los datos; el estado, la seña, la política y el depósito no cambian
    public function testElPropietarioEditaLosDatos(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id'], [
            'estado_publicacion' => 'publicada',
            'porcentaje_sena'    => 10,
            'deposito_garantia'  => 5000,
        ]);
        $antes = $this->filaUnidad($unidadId);

        $respuesta = $this->iniciarSesion($propietario['mail'])->post('/unidades/editar', $this->datosValidos([
            'unidad_id'          => $unidadId,
            'nombre'             => 'Nuevo nombre',
            'localidad_id'       => $this->idLocalidad('Pinamar'),
            'capacidad'          => 6,
            'caracteristicas'    => '',
            'estado_publicacion' => 'borrador',
            'deposito_garantia'  => 1,
        ]));

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['ok' => 'Guardamos los cambios'], $respuesta->json());
        $despues = $this->filaUnidad($unidadId);
        $this->assertSame('Nuevo nombre', $despues['nombre']);
        $this->assertSame($this->idLocalidad('Pinamar'), (int) $despues['localidad_id']);
        $this->assertSame(6, (int) $despues['capacidad']);
        $this->assertNull($despues['caracteristicas']);
        foreach (['estado_publicacion', 'disponible', 'operador_id', 'deposito_garantia', 'porcentaje_sena',
                  'retencion_mas_de_30', 'retencion_de_15_a_30', 'retencion_menos_de_15', 'propietario_id'] as $columna) {
            $this->assertSame($antes[$columna], $despues[$columna], $columna);
        }
    }

    //Editar valida igual que crear
    public function testEditarValidaLosDatos(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);

        $respuesta = $this->iniciarSesion($propietario['mail'])->post('/unidades/editar', $this->datosValidos([
            'unidad_id' => $unidadId,
            'capacidad' => 51,
        ]));

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('La capacidad tiene que ser de 1 a 50 personas', $respuesta->json()['error']);
        $this->assertSame(4, (int) $this->filaUnidad($unidadId)['capacidad']);
    }

    //Una unidad ajena y el administrador asignado reciben 404: los datos son solo del propietario
    public function testSoloElPropietarioEditaLosDatos(): void
    {
        $administrador = $this->crearAdministradorEnBase();
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id'], ['nombre' => 'Original']);
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);
        $datos = $this->datosValidos(['unidad_id' => $unidadId, 'nombre' => 'Cambiado']);

        $otroPropietario = $this->iniciarSesion($this->crearPropietarioEnBase()['mail'])->post('/unidades/editar', $datos);
        $this->assertSame(404, $otroPropietario->codigo);
        $elAdministrador = $this->iniciarSesion($administrador['mail'])->post('/unidades/editar', $datos);
        $this->assertSame(403, $elAdministrador->codigo);//PermisosUnidad::delPropietario controla el rol antes que la unidad
        $this->assertSame('Original', $this->filaUnidad($unidadId)['nombre']);
    }

    // ---------- Depósito ----------

    //El propietario carga el depósito y lo vacía con null
    public function testElPropietarioCargaYVaciaElDeposito(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $cliente = $this->iniciarSesion($propietario['mail']);

        $respuesta = $cliente->post('/unidades/deposito', ['unidad_id' => $unidadId, 'deposito_garantia' => '15000.5']);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['ok' => 'Guardamos el depósito', 'deposito_garantia' => '15000.50'], $respuesta->json());
        $this->assertSame('15000.50', $this->filaUnidad($unidadId)['deposito_garantia']);

        $respuesta = $cliente->post('/unidades/deposito', ['unidad_id' => $unidadId, 'deposito_garantia' => null]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertNull($respuesta->json()['deposito_garantia']);
        $this->assertNull($this->filaUnidad($unidadId)['deposito_garantia']);
    }

    //El administrador asignado también puede cargar el depósito (US2.6)
    public function testElAdministradorAsignadoCargaElDeposito(): void
    {
        $administrador = $this->crearAdministradorEnBase();
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id']);
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $respuesta = $this->iniciarSesion($administrador['mail'])
            ->post('/unidades/deposito', ['unidad_id' => $unidadId, 'deposito_garantia' => 20000]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('20000.00', $this->filaUnidad($unidadId)['deposito_garantia']);
    }

    //Un monto inválido responde 422 y no cambia el depósito
    public function testUnDepositoInvalido(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id'], ['deposito_garantia' => 5000]);

        $respuesta = $this->iniciarSesion($propietario['mail'])
            ->post('/unidades/deposito', ['unidad_id' => $unidadId, 'deposito_garantia' => '-10']);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El depósito tiene que ser un monto en pesos, con hasta 2 decimales', $respuesta->json()['error']);
        $this->assertSame('5000.00', $this->filaUnidad($unidadId)['deposito_garantia']);
    }

    //Un administrador que no la administra y otro propietario reciben 404
    public function testNadieMasCargaElDeposito(): void
    {
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id']);

        foreach ([$this->crearAdministradorEnBase(), $this->crearPropietarioEnBase()] as $usuario) {
            $respuesta = $this->iniciarSesion($usuario['mail'])
                ->post('/unidades/deposito', ['unidad_id' => $unidadId, 'deposito_garantia' => 1000]);
            $this->assertSame(404, $respuesta->codigo);
        }
        $this->assertNull($this->filaUnidad($unidadId)['deposito_garantia']);
    }
}
