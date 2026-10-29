<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//El precio de cada noche de una unidad (US2.7): verlo por rango y cargarlo, con su historial
//(un precio guardado nunca se modifica ni se borra: se cierra y se crea otro).
final class PreciosTest extends CasoDeIntegracion
{
    //Un propietario con una unidad y su sesión iniciada: [cliente, id de la unidad, id del propietario]
    private function propietarioConUnidad(array $cambiosPropietario = []): array
    {
        $propietario = $this->crearPropietarioEnBase($cambiosPropietario);
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        return [$this->iniciarSesion($propietario['mail']), $unidadId, $propietario['id']];
    }

    //Carga un precio por la API y devuelve la respuesta (el test controla el código)
    private function cargar(ClienteApi $cliente, int $unidadId, string $desde, string $hasta, mixed $precio)
    {
        return $cliente->post('/unidades/precios/cargar', [
            'unidad_id' => $unidadId, 'desde' => $this->fecha($desde), 'hasta' => $this->fecha($hasta), 'precio' => $precio,
        ]);
    }

    //Todas las filas de una noche (vigentes y cerradas), de la más vieja a la más nueva
    private function historial(int $unidadId, string $desplazamiento): array
    {
        $consulta = $this->pdo()->prepare(
            'SELECT precio, vigente_hasta IS NULL AS vigente FROM precio_noche WHERE unidad_id = ? AND fecha = ? ORDER BY vigente_desde'
        );
        $consulta->execute([$unidadId, $this->fecha($desplazamiento)]);
        return array_map(fn ($fila) => [$fila['precio'], (bool) $fila['vigente']], $consulta->fetchAll());
    }

    // ---------- cargar ----------

    //Cargar un rango crea una fila vigente por noche, con quién la cargó
    public function testCargarCreaUnaFilaPorNoche(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();

        $respuesta = $this->cargar($cliente, $unidadId, '+1 day', '+5 days', 70000);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['ok' => 'Guardamos el precio de 5 noches', 'noches_cambiadas' => 5], $respuesta->json());
        $filas = $this->pdo()->query("SELECT fecha, precio, creado_por FROM precio_noche WHERE unidad_id = $unidadId AND vigente_hasta IS NULL ORDER BY fecha")->fetchAll();
        $this->assertSame($this->fecha('+1 day'), $filas[0]['fecha']);
        $this->assertSame($this->fecha('+5 days'), $filas[4]['fecha']);
        $this->assertCount(5, $filas);
        $this->assertSame(['70000.00'], array_unique(array_column($filas, 'precio')));
        $this->assertSame([$propietarioId], array_unique(array_column($filas, 'creado_por')));
    }

    //El historial: cambiar el precio cierra el anterior (no lo borra ni lo modifica) y deja uno solo vigente
    public function testCambiarElPrecioGuardaElHistorial(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+10 days'), 70000, $propietarioId);

        $respuesta = $this->cargar($cliente, $unidadId, '+5 days', '+15 days', '85000.50');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(11, $respuesta->json()['noches_cambiadas']);
        $this->assertSame([['70000.00', true]], $this->historial($unidadId, '+4 days'));                      //fuera del rango: no cambia
        $this->assertSame([['70000.00', false], ['85000.50', true]], $this->historial($unidadId, '+5 days'));  //cambió: el viejo queda cerrado
        $this->assertSame([['85000.50', true]], $this->historial($unidadId, '+15 days'));                     //no tenía precio: solo el nuevo
        $vigentesPorNoche = $this->pdo()->query(
            "SELECT MAX(c) FROM (SELECT COUNT(*) c FROM precio_noche WHERE unidad_id = $unidadId AND vigente_hasta IS NULL GROUP BY fecha) t"
        )->fetchColumn();
        $this->assertSame(1, (int) $vigentesPorNoche);
    }

    //Las noches que ya tenían ese precio no se tocan: no se llena el historial de filas iguales
    public function testLasNochesConElMismoPrecioNoCambian(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+5 days'), 70000, $propietarioId);

        $respuesta = $this->cargar($cliente, $unidadId, '+1 day', '+5 days', '70000');

        $this->assertSame(['ok' => 'Esas noches ya tenían ese precio', 'noches_cambiadas' => 0], $respuesta->json());
        $this->assertSame([['70000.00', true]], $this->historial($unidadId, '+3 days'));
    }

    //Una sola noche, con las dos fechas iguales
    public function testUnaSolaNoche(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $respuesta = $this->cargar($cliente, $unidadId, 'today', 'today', 50000);

        $this->assertSame('Guardamos el precio de 1 noche', $respuesta->json()['ok']);
    }

    //Cada dato inválido se rechaza con su mensaje, y no se guarda nada
    public function testValidaLosDatos(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $casos = [
            'desde en el pasado'   => [['desde' => $this->fecha('-1 day')], 'Solo podés cargar precios de hoy en adelante'],
            'fecha que no existe'  => [['hasta' => '2027-02-30'], 'Las fechas tienen que tener el formato AAAA-MM-DD'],
            'desde después'        => [['desde' => $this->fecha('+5 days'), 'hasta' => $this->fecha('+4 days')], 'La fecha "desde" no puede ser posterior a la fecha "hasta"'],
            'más de 366 noches'    => [['hasta' => $this->fecha('+367 days')], 'El rango puede tener hasta 366 noches'],
            'precio 0'             => [['precio' => 0], 'El precio tiene que ser un monto mayor que 0, con hasta 2 decimales'],
            'precio negativo'      => [['precio' => -100], 'El precio tiene que ser un monto mayor que 0, con hasta 2 decimales'],
            'precio con 3 decimales' => [['precio' => '100.555'], 'El precio tiene que ser un monto mayor que 0, con hasta 2 decimales'],
            'precio con coma'      => [['precio' => '100,50'], 'El precio tiene que ser un monto mayor que 0, con hasta 2 decimales'],
            'precio enorme'        => [['precio' => '12345678901'], 'El precio tiene que ser un monto mayor que 0, con hasta 2 decimales'],
            'precio vacío'         => [['precio' => ''], 'El precio tiene que ser un monto mayor que 0, con hasta 2 decimales'],
            'precio true'          => [['precio' => true], 'El precio tiene que ser un monto mayor que 0, con hasta 2 decimales'],
        ];

        foreach ($casos as $caso => [$cambios, $mensaje]) {
            $datos = array_merge(['unidad_id' => $unidadId, 'desde' => $this->fecha('+1 day'), 'hasta' => $this->fecha('+5 days'), 'precio' => 70000], $cambios);
            $respuesta = $cliente->post('/unidades/precios/cargar', $datos);
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame($mensaje, $respuesta->json()['error'], $caso);
        }
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM precio_noche WHERE unidad_id = $unidadId")->fetchColumn());
    }

    //Cargar el precio de los próximos 6 meses hace que a la unidad no le falte nada para publicarse (tiene foto)
    public function testConPrecioYaNoLeFaltaNadaParaPublicarse(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $this->agregarFotoEnBase($unidadId);
        $this->assertNotEmpty($cliente->get('/unidades/resumen', ['id' => $unidadId])->json()['unidad']['faltantes']);

        $this->cargar($cliente, $unidadId, '+1 day', '+6 months', 70000);

        $this->assertSame([], $cliente->get('/unidades/resumen', ['id' => $unidadId])->json()['unidad']['faltantes']);
    }

    // ---------- ver ----------

    //Cada noche del rango con su precio vigente, su temporada y su mínimo (1 si no tiene temporada)
    public function testVerDevuelveCadaNocheConPrecioYTemporada(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $this->crearTemporadaEnBase($unidadId, 'alta', $this->fecha('+2 days'), $this->fecha('+3 days'), 7);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+2 days'), 70000, $propietarioId);
        $this->cargar($cliente, $unidadId, '+2 days', '+2 days', 90000);//la noche +2 cambió de precio: se ve el vigente

        $respuesta = $cliente->get('/unidades/precios', ['id' => $unidadId, 'desde' => $this->fecha('+1 day'), 'hasta' => $this->fecha('+4 days')]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['cobra_iva' => false, 'noches' => [
            ['fecha' => $this->fecha('+1 day'), 'precio' => '70000.00', 'temporada' => null, 'minimo_noches' => 1],
            ['fecha' => $this->fecha('+2 days'), 'precio' => '90000.00', 'temporada' => 'alta', 'minimo_noches' => 7],
            ['fecha' => $this->fecha('+3 days'), 'precio' => null, 'temporada' => 'alta', 'minimo_noches' => 7],
            ['fecha' => $this->fecha('+4 days'), 'precio' => null, 'temporada' => null, 'minimo_noches' => 1],
        ]], $respuesta->json());
    }

    //Se pueden ver noches pasadas (el calendario del mes en curso las muestra), pero no más de 366
    public function testVerNochesPasadasYElLimiteDelRango(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $pasadas = $cliente->get('/unidades/precios', ['id' => $unidadId, 'desde' => $this->fecha('-10 days'), 'hasta' => $this->fecha()]);
        $this->assertSame(200, $pasadas->codigo, $pasadas->cuerpo);
        $this->assertCount(11, $pasadas->json()['noches']);

        $muchas = $cliente->get('/unidades/precios', ['id' => $unidadId, 'desde' => $this->fecha(), 'hasta' => $this->fecha('+366 days')]);
        $this->assertSame(422, $muchas->codigo);
        $this->assertSame(422, $cliente->get('/unidades/precios', ['id' => $unidadId, 'desde' => 'ayer', 'hasta' => $this->fecha()])->codigo);
    }

    //cobra_iva dice si el propietario cobra IVA: la pantalla aclara que el precio lo incluye (RN-13)
    public function testVerDiceSiElPropietarioCobraIva(): void
    {
        [$cliente, $unidadId, $propietarioId] = $this->propietarioConUnidad();
        $this->pdo()->prepare("UPDATE propietarios SET cobra_iva = 1, cuit = ?, razon_social = 'Marisa SRL', domicilio_fiscal = 'Calle 1' WHERE usuario_id = ?")
            ->execute([\Tests\Soporte\Datos::cuit(), $propietarioId]);

        $respuesta = $cliente->get('/unidades/precios', ['id' => $unidadId, 'desde' => $this->fecha(), 'hasta' => $this->fecha()]);

        $this->assertTrue($respuesta->json()['cobra_iva']);
    }

    // ---------- permisos ----------

    //Ver: el propietario y el administrador asignado. Cargar: solo el propietario (el administrador recibe 403).
    //Otro propietario 404, sin sesión 401.
    public function testSoloElPropietarioDeLaUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $quienes = [//[cliente, código al ver, código al cargar]
            'otro propietario'          => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404, 404],
            'el administrador asignado' => [$this->iniciarSesion($administrador['mail']), 200, 403],
            'sin sesión'                => [$this->cliente(), 401, 401],
        ];
        foreach ($quienes as $quien => [$cliente, $codigoVer, $codigoCargar]) {
            $ver = $cliente->get('/unidades/precios', ['id' => $unidadId, 'desde' => $this->fecha(), 'hasta' => $this->fecha('+5 days')]);
            $this->assertSame($codigoVer, $ver->codigo, "$quien: ver");
            $this->assertSame($codigoCargar, $this->cargar($cliente, $unidadId, '+1 day', '+5 days', 70000)->codigo, "$quien: cargar");
        }
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM precio_noche WHERE unidad_id = $unidadId")->fetchColumn());
    }
}
