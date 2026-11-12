<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//Los descuentos por estadía larga (US2.10, decisión #69): el propietario carga escalones por unidad y a cada estadía
//se le aplica el más alto que alcanza. El total con el descuento es la base de la seña y del saldo.
final class DescuentosTest extends CasoDeIntegracion
{
    private array $propietario;

    //Antes de cada test: un propietario para las unidades
    protected function setUp(): void
    {
        $this->propietario = $this->crearPropietarioEnBase();
    }

    //Una unidad publicada con $70.000 por noche para los próximos 90 días. Devuelve su id.
    private function unidadPublicada(): int
    {
        $unidadId = $this->crearUnidadEnBase($this->propietario['id'], ['estado_publicacion' => 'publicada', 'capacidad' => 4]);
        $this->agregarFotoEnBase($unidadId);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $this->propietario['id']);
        return $unidadId;
    }

    //La sesión del propietario
    private function sesionDelPropietario(): ClienteApi
    {
        return $this->iniciarSesion($this->propietario['mail']);
    }

    //Los escalones de 10 % desde 10 noches y 15 % desde 14, guardados por la API
    private function cargarEscalones(int $unidadId): void
    {
        $respuesta = $this->sesionDelPropietario()->post('/unidades/descuentos', ['unidad_id' => $unidadId, 'descuentos' => [
            ['noches_minimas' => 14, 'porcentaje' => '15'],
            ['noches_minimas' => 10, 'porcentaje' => 10],
        ]]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
    }

    //Un huésped con el mail verificado reserva por la API: devuelve la respuesta
    private function reservar(int $unidadId, int $noches)
    {
        $huesped = $this->crearUsuarioEnBase('huesped');
        $this->pdo()->prepare('UPDATE usuarios SET mail_verificado_en = UTC_TIMESTAMP() WHERE id = ?')->execute([$huesped['id']]);
        return $this->iniciarSesion($huesped['mail'])->post('/reservas/crear', [
            'unidad_id' => $unidadId, 'ingreso' => $this->fecha('+20 days'), 'egreso' => $this->fecha('+' . (20 + $noches) . ' days'),
            'personas' => 2, 'acepta_politica' => true,
        ]);
    }

    //Guardar y ver: acepta números y texto, ordena por noches y responde los escalones normalizados
    public function testGuardaYMuestraLosEscalonesOrdenados(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->cargarEscalones($unidadId);

        $esperado = ['descuentos' => [
            ['noches_minimas' => 10, 'porcentaje' => '10.00'],
            ['noches_minimas' => 14, 'porcentaje' => '15.00'],
        ]];
        $this->assertSame($esperado, $this->sesionDelPropietario()->get('/unidades/descuentos', ['id' => $unidadId])->json());
    }

    //Guardar reemplaza todos los escalones; una lista vacía los borra
    public function testGuardarReemplazaYUnaListaVaciaLosBorra(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->cargarEscalones($unidadId);
        $cliente = $this->sesionDelPropietario();

        $respuesta = $cliente->post('/unidades/descuentos', ['unidad_id' => $unidadId, 'descuentos' => [['noches_minimas' => 7, 'porcentaje' => 5]]]);
        $this->assertSame(['ok' => 'Guardamos los descuentos', 'descuentos' => [['noches_minimas' => 7, 'porcentaje' => '5.00']]], $respuesta->json());

        $respuesta = $cliente->post('/unidades/descuentos', ['unidad_id' => $unidadId, 'descuentos' => []]);
        $this->assertSame(['ok' => 'Sacamos los descuentos de la unidad', 'descuentos' => []], $respuesta->json());
        $this->assertSame(['descuentos' => []], $cliente->get('/unidades/descuentos', ['id' => $unidadId])->json());
    }

    //Los datos inválidos no cambian nada y dicen qué está mal
    public function testValidaLosEscalones(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->cargarEscalones($unidadId);
        $cliente = $this->sesionDelPropietario();
        $noches = 'Las noches tienen que ser un número de 2 a 60';
        $porcentaje = 'El descuento tiene que ser un porcentaje mayor que 0 y menor que 100, con hasta 2 decimales';
        $casos = [
            [[['noches_minimas' => 1, 'porcentaje' => 10]], $noches],
            [[['noches_minimas' => 61, 'porcentaje' => 10]], $noches],
            [[['noches_minimas' => '7.5', 'porcentaje' => 10]], $noches],
            [[['noches_minimas' => true, 'porcentaje' => 10]], $noches],
            [[['noches_minimas' => 7, 'porcentaje' => 0]], $porcentaje],
            [[['noches_minimas' => 7, 'porcentaje' => 100]], $porcentaje],
            [[['noches_minimas' => 7, 'porcentaje' => '10.555']], $porcentaje],
            [[['noches_minimas' => 7, 'porcentaje' => '-5']], $porcentaje],
            [[['noches_minimas' => 7, 'porcentaje' => 10], ['noches_minimas' => 7, 'porcentaje' => 20]], 'Hay dos descuentos desde la misma cantidad de noches'],
            [['noches_minimas' => 7, 'porcentaje' => 10], 'Mandá los descuentos como una lista'],
            [null, 'Mandá los descuentos como una lista'],
            [['texto'], 'Cada descuento tiene que tener noches y porcentaje'],
            [array_fill(0, 11, ['noches_minimas' => 7, 'porcentaje' => 10]), 'Podés cargar hasta 10 descuentos por unidad'],
        ];
        foreach ($casos as [$descuentos, $mensaje]) {
            $respuesta = $cliente->post('/unidades/descuentos', ['unidad_id' => $unidadId, 'descuentos' => $descuentos]);
            $this->assertSame(422, $respuesta->codigo, $mensaje);
            $this->assertSame($mensaje, $respuesta->json()['error']);
        }
        $this->assertCount(2, $cliente->get('/unidades/descuentos', ['id' => $unidadId])->json()['descuentos']);//no cambió nada
    }

    //Solo el propietario: el administrador asignado recibe 403, otro propietario 404 y sin sesión 401
    public function testSoloElPropietario(): void
    {
        $unidadId = $this->unidadPublicada();
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);
        $otro = $this->crearPropietarioEnBase();

        $this->assertSame(403, $this->iniciarSesion($administrador['mail'])->get('/unidades/descuentos', ['id' => $unidadId])->codigo);
        $this->assertSame(404, $this->iniciarSesion($otro['mail'])->get('/unidades/descuentos', ['id' => $unidadId])->codigo);
        $respuesta = $this->iniciarSesion($otro['mail'])->post('/unidades/descuentos', ['unidad_id' => $unidadId, 'descuentos' => []]);
        $this->assertSame(404, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(401, $this->cliente()->get('/unidades/descuentos', ['id' => $unidadId])->codigo);
    }

    //A cada estadía, el escalón más alto que alcanza: 9 noches sin descuento, 10 a 13 con 10 %, 14 o más con 15 %
    public function testElDetalleAplicaElEscalonMasAlto(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->cargarEscalones($unidadId);

        foreach ([9 => ['630000.00', '0.00', '630000.00'], 10 => ['700000.00', '10.00', '630000.00'],
                  13 => ['910000.00', '10.00', '819000.00'], 14 => ['980000.00', '15.00', '833000.00']] as $noches => $esperado) {
            $estadia = $this->cliente()->get('/alojamiento', [
                'id' => $unidadId, 'ingreso' => $this->fecha('+20 days'), 'egreso' => $this->fecha('+' . (20 + $noches) . ' days'), 'personas' => 2,
            ])->json()['estadia'];
            $this->assertSame($esperado, [$estadia['importe_sin_descuento'], $estadia['porcentaje_descuento'], $estadia['precio_total']], "$noches noches");
        }
    }

    //La reserva guarda la foto del descuento, y la seña y el saldo salen del total con el descuento.
    //Si después el propietario cambia los descuentos, la reserva no cambia.
    public function testLaReservaGuardaElDescuentoYCobraSobreElTotalConDescuento(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->cargarEscalones($unidadId);

        $respuesta = $this->reservar($unidadId, 10);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $reserva = $respuesta->json()['reserva'];
        $this->assertSame(['700000.00', '10.00', '630000.00', '126000.00'],//seña del 20 % sobre el total con descuento
            [$reserva['importe_sin_descuento'], $reserva['porcentaje_descuento'], $reserva['importe_total'], $reserva['importe_sena']]);

        $this->sesionDelPropietario()->post('/unidades/descuentos', ['unidad_id' => $unidadId, 'descuentos' => []]);
        $guardada = $this->pdo()->query("SELECT importe_sin_descuento, porcentaje_descuento, importe_total, importe_sena, monto_faltante
                                         FROM reservas WHERE id = {$reserva['id']}")->fetch();
        $this->assertSame(['700000.00', '10.00', '630000.00', '126000.00', '504000.00'], array_values($guardada));
    }
}
