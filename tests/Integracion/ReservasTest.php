<?php

namespace Tests\Integracion;

use Tests\Soporte\Datos;

//Crear una reserva (Épica 4, entrega 4 sin pagos): POST /reservas/crear.
//La reserva se crea pendiente, guarda la foto de las condiciones y ocupa sus noches (también para los bloqueos).
final class ReservasTest extends CasoDeIntegracion
{
    private array $propietario;

    //Antes de cada test: un propietario para las unidades
    protected function setUp(): void
    {
        $this->propietario = $this->crearPropietarioEnBase();
    }

    //Una unidad publicada para 4 personas, con $70.000 por noche para los próximos 90 días. Devuelve su id.
    private function unidadPublicada(array $cambios = []): int
    {
        $unidadId = $this->crearUnidadEnBase($this->propietario['id'], array_merge([
            'nombre' => 'Cabaña del Mar', 'estado_publicacion' => 'publicada', 'capacidad' => 4,
        ], $cambios));
        $this->agregarFotoEnBase($unidadId);
        $this->cargarPreciosEnBase($unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $this->propietario['id']);
        return $unidadId;
    }

    //Un usuario con el mail verificado (sin verificar no puede reservar). Devuelve sus datos, con el id.
    private function conMailVerificado(array $usuario): array
    {
        $this->pdo()->prepare('UPDATE usuarios SET mail_verificado_en = UTC_TIMESTAMP() WHERE id = ?')->execute([$usuario['id']]);
        return $usuario;
    }

    //Los datos de una reserva válida de 3 noches (dentro de 10 días), con los cambios que pida el test
    private function datosReserva(int $unidadId, array $cambios = []): array
    {
        return array_merge([
            'unidad_id' => $unidadId, 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+13 days'),
            'personas' => 2, 'acepta_politica' => true,
        ], $cambios);
    }

    //Una reserva guardada directo en la base (para estados y fechas que la API no deja crear). Devuelve su id.
    private function reservaEnBase(int $unidadId, int $huespedId, string $ingreso, string $egreso, string $estado = 'pendiente'): int
    {
        $this->pdo()->prepare(
            "INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado, importe_total,
                                   porcentaje_sena, importe_sena, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15,
                                   politica_aceptada_en)
             VALUES (?, ?, ?, ?, 2, ?, 210000, 20, 42000, 30, 50, 70, UTC_TIMESTAMP())"
        )->execute([$unidadId, $huespedId, $ingreso, $egreso, $estado]);
        return (int) $this->pdo()->lastInsertId();
    }

    //Reserva con un huésped nuevo con el mail verificado y devuelve la respuesta
    private function reservar(int $unidadId, array $cambios = [])
    {
        $huesped = $this->conMailVerificado($this->crearUsuarioEnBase('huesped'));
        return $this->iniciarSesion($huesped['mail'])->post('/reservas/crear', $this->datosReserva($unidadId, $cambios));
    }

    // ---------- crear ----------

    //Queda pendiente, con el total, la seña y la política de la unidad copiados (son las condiciones que aceptó)
    public function testReservaYGuardaLasCondiciones(): void
    {
        $unidadId = $this->unidadPublicada();
        $huesped = $this->conMailVerificado($this->crearUsuarioEnBase('huesped'));

        $respuesta = $this->iniciarSesion($huesped['mail'])->post('/reservas/crear', $this->datosReserva($unidadId));

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $reservaId = $respuesta->json()['reserva']['id'];
        $venceEn = $respuesta->json()['reserva']['vence_en'];//el plazo para pagar la seña se prueba en PagosTest
        $this->assertSame([
            'ok'      => 'Reservaste Cabaña del Mar: pagá la seña antes de las ' . (new \DateTimeImmutable($venceEn))
                ->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('H:i') . ' para confirmarla',
            'reserva' => [
                'id' => $reservaId, 'estado' => 'pendiente', 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+13 days'),
                'noches' => 3, 'personas' => 2,
                'importe_sin_descuento' => '210000.00', 'porcentaje_descuento' => '0.00',//la unidad no tiene descuentos (#69)
                'importe_total' => '210000.00', 'importe_sena' => '42000.00',//20% de seña
                'vence_en' => $venceEn,
            ],
        ], $respuesta->json());

        $fila = $this->pdo()->query("SELECT * FROM reservas WHERE id = $reservaId")->fetch();
        $this->assertSame($huesped['id'], (int) $fila['huesped_id']);
        $this->assertSame('pendiente', $fila['estado']);
        $this->assertSame(['20.00', '30.00', '50.00', '70.00'], [
            $fila['porcentaje_sena'], $fila['retencion_mas_de_30'], $fila['retencion_de_15_a_30'], $fila['retencion_menos_de_15'],
        ]);
        $this->assertNotNull($fila['politica_aceptada_en']);
    }

    //Un propietario también es huésped: puede reservar la unidad de otro, pero no la suya
    public function testElPropietarioReservaSoloUnidadesAjenas(): void
    {
        $otro = $this->conMailVerificado($this->crearPropietarioEnBase());
        $cliente = $this->iniciarSesion($otro['mail']);

        $this->assertSame(201, $cliente->post('/reservas/crear', $this->datosReserva($this->unidadPublicada()))->codigo);

        $suya = $this->crearUnidadEnBase($otro['id'], ['estado_publicacion' => 'publicada']);
        $this->cargarPreciosEnBase($suya, $this->fecha('+1 day'), $this->fecha('+30 days'), 50000, $otro['id']);
        $respuesta = $cliente->post('/reservas/crear', $this->datosReserva($suya));
        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('No podés reservar tu propia unidad', $respuesta->json()['error']);
    }

    // ---------- noches ocupadas ----------

    //Las noches reservadas ya no se pueden reservar ni aparecen libres en el detalle;
    //la noche del egreso sí queda libre: otro huésped puede entrar ese día (RN-08)
    public function testLasNochesReservadasQuedanOcupadas(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->assertSame(201, $this->reservar($unidadId)->codigo);

        $superpuesta = $this->reservar($unidadId, ['ingreso' => $this->fecha('+12 days'), 'egreso' => $this->fecha('+15 days')]);
        $this->assertSame(409, $superpuesta->codigo);
        $this->assertSame('Hay noches ocupadas en esas fechas', $superpuesta->json()['error']);

        $detalle = $this->cliente()->get('/alojamiento', ['id' => $unidadId, 'ingreso' => $this->fecha('+11 days'), 'egreso' => $this->fecha('+12 days')]);
        $this->assertFalse($detalle->json()['estadia']['disponible']);

        $desdeElEgreso = $this->reservar($unidadId, ['ingreso' => $this->fecha('+13 days'), 'egreso' => $this->fecha('+15 days')]);
        $this->assertSame(201, $desdeElEgreso->codigo, $desdeElEgreso->cuerpo);
    }

    //Una reserva cancelada o expirada no ocupa sus noches
    public function testLasCanceladasYExpiradasNoOcupan(): void
    {
        $unidadId = $this->unidadPublicada();
        $huespedId = $this->crearUsuarioEnBase('huesped')['id'];
        foreach (['cancelada', 'expirada'] as $estado) {
            $this->reservaEnBase($unidadId, $huespedId, $this->fecha('+10 days'), $this->fecha('+13 days'), $estado);
        }

        $this->assertSame(201, $this->reservar($unidadId)->codigo);
    }

    //No se puede bloquear una noche reservada: sería una cancelación del propietario (409). Desde el egreso, sí.
    public function testNoSePuedeBloquearUnaNocheReservada(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->assertSame(201, $this->reservar($unidadId)->codigo);
        $cliente = $this->iniciarSesion($this->propietario['mail']);

        $respuesta = $cliente->post('/unidades/bloqueos/crear', [
            'unidad_id' => $unidadId, 'fecha_desde' => $this->fecha('+12 days'), 'fecha_hasta' => $this->fecha('+20 days'), 'motivo' => 'mantenimiento',
        ]);
        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame(sprintf(
            'Esas fechas tienen una reserva, con ingreso el %s y egreso el %s: no se pueden bloquear',
            (new \DateTimeImmutable($this->fecha('+10 days')))->format('d/m/Y'),
            (new \DateTimeImmutable($this->fecha('+13 days')))->format('d/m/Y')
        ), $respuesta->json()['error']);

        $desdeElEgreso = $cliente->post('/unidades/bloqueos/crear', [
            'unidad_id' => $unidadId, 'fecha_desde' => $this->fecha('+13 days'), 'fecha_hasta' => $this->fecha('+20 days'), 'motivo' => 'mantenimiento',
        ]);
        $this->assertSame(201, $desdeElEgreso->codigo, $desdeElEgreso->cuerpo);
    }

    //Respeta el mínimo de noches de la temporada (RN-09), igual que el detalle
    public function testRespetaElMinimoDeNoches(): void
    {
        $unidadId = $this->unidadPublicada();
        $this->crearTemporadaEnBase($unidadId, 'alta', $this->fecha('+1 day'), $this->fecha('+60 days'), 5);

        $respuesta = $this->reservar($unidadId);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Mínimo 5 noches en temporada alta', $respuesta->json()['error']);
    }

    // ---------- validaciones ----------

    //Saltear completar-datos no permite reservar ni guardar un DNI enviado en la reserva.
    public function testExigeDniAntesDeReservar(): void
    {
        $unidadId = $this->unidadPublicada();
        $huesped = $this->conMailVerificado($this->crearUsuarioEnBase('huesped', ['dni' => null]));
        $cliente = $this->iniciarSesion($huesped['mail']);
        $datos = $this->datosReserva($unidadId, ['dni' => Datos::dni()]);

        $respuesta = $cliente->post('/reservas/crear', $datos);

        $this->assertSame(422, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Completá tu DNI para reservar', $respuesta->json()['error']);
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM reservas WHERE unidad_id = $unidadId")->fetchColumn());
        $this->assertNull($this->filaUsuario($huesped['mail'])['dni']);

        $this->assertSame(200, $cliente->post('/completar-datos', ['dni' => Datos::dni()])->codigo);
        $this->assertSame(201, $cliente->post('/reservas/crear', $datos)->codigo);
    }

    //Completar el DNI desde otra sesión también habilita la reserva sin volver a iniciar sesión.
    public function testReconoceElDniCompletadoEnOtraSesion(): void
    {
        $huesped = $this->conMailVerificado($this->crearUsuarioEnBase('huesped', ['dni' => null]));
        $cliente = $this->iniciarSesion($huesped['mail']);
        $otraSesion = $this->iniciarSesion($huesped['mail']);
        $this->assertSame(200, $otraSesion->post('/completar-datos', ['dni' => Datos::dni()])->codigo);

        $respuesta = $cliente->post('/reservas/crear', $this->datosReserva($this->unidadPublicada()));
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
    }

    //Fechas, personas y la política se validan con mensajes claros; nada queda guardado
    public function testValidaLosDatos(): void
    {
        $unidadId = $this->unidadPublicada();
        $casos = [
            'Elegí las fechas de ingreso y de egreso'                => ['ingreso' => ''],
            'El ingreso tiene que ser de mañana en adelante'        => ['ingreso' => $this->fecha(), 'egreso' => $this->fecha('+2 days')],
            'El egreso tiene que ser posterior al ingreso'          => ['egreso' => $this->fecha('+10 days')],
            'Indicá cuántas personas van a ser (de 1 a 50)'         => ['personas' => 0],
            'Tiene lugar para 4 personas'                           => ['personas' => 5],
            'Para reservar tenés que aceptar la política de cancelación' => ['acepta_politica' => false],
        ];
        foreach ($casos as $mensaje => $cambios) {
            $respuesta = $this->reservar($unidadId, $cambios);
            $this->assertSame(422, $respuesta->codigo, $mensaje);
            $this->assertSame($mensaje, $respuesta->json()['error']);
        }
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM reservas WHERE unidad_id = $unidadId")->fetchColumn());
    }

    //Una unidad sin publicar o inexistente "no existe" (404); una no disponible no se puede reservar (409)
    public function testSoloUnidadesPublicadasYDisponibles(): void
    {
        $casos = [
            404 => [$this->unidadPublicada(['estado_publicacion' => 'borrador']), $this->unidadPublicada(['estado_publicacion' => 'pausada']), 999999],
            409 => [$this->unidadPublicada(['disponible' => 0, 'motivo_no_disponible' => 'Se rompió el techo'])],
        ];
        foreach ($casos as $codigo => $unidades) {
            foreach ($unidades as $unidadId) {
                $this->assertSame($codigo, $this->reservar($unidadId)->codigo, "unidad $unidadId");
            }
        }
    }

    // ---------- mis reservas ----------

    //Solo las suyas, de la más nueva a la más vieja (también las canceladas), con el alojamiento y si se puede cancelar
    public function testMisReservas(): void
    {
        $unidadId = $this->unidadPublicada();
        $huesped = $this->conMailVerificado($this->crearUsuarioEnBase('huesped'));
        $cliente = $this->iniciarSesion($huesped['mail']);
        $creada = $cliente->post('/reservas/crear', $this->datosReserva($unidadId))->json()['reserva'];
        $proxima = $creada['id'];
        $cancelada = $this->reservaEnBase($unidadId, $huesped['id'], $this->fecha('+30 days'), $this->fecha('+32 days'), 'cancelada');
        $this->assertSame(201, $this->reservar($unidadId, ['ingreso' => $this->fecha('+40 days'), 'egreso' => $this->fecha('+42 days')])->codigo);//de otro

        $respuesta = $cliente->get('/reservas/mias');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $alojamiento = [
            'id' => $unidadId, 'nombre' => 'Cabaña del Mar', 'localidad' => 'Las Grutas', 'provincia' => 'Río Negro',
            'foto_principal' => 'https://res.cloudinary.com/ynclnyuh/image/upload/unidades/prueba.jpg',
        ];
        $this->assertSame(['reservas' => [
            [
                'id' => $cancelada, 'estado' => 'cancelada', 'ingreso' => $this->fecha('+30 days'), 'egreso' => $this->fecha('+32 days'),
                'noches' => 2, 'personas' => 2, 'importe_total' => '210000.00', 'importe_sena' => '42000.00',
                'vence_en' => null, 'puede_pagar' => false, 'puede_cancelar' => false,
                'puede_extender' => false, 'extiende_reserva_id' => null, 'descuento' => null, 'no_show' => null,//La 25
                'saldo' => null, 'fecha_cobro' => null, 'puede_pagar_saldo' => false,
                'cancelacion' => ['por' => null, 'motivo' => null, 'excepcion' => null],//cancelada antes de anotar quién
                'alojamiento' => $alojamiento,
            ],
            [
                'id' => $proxima, 'estado' => 'pendiente', 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+13 days'),
                'noches' => 3, 'personas' => 2, 'importe_total' => '210000.00', 'importe_sena' => '42000.00',
                'vence_en' => $creada['vence_en'], 'puede_pagar' => true, 'puede_cancelar' => true,
                'puede_extender' => false, 'extiende_reserva_id' => null, 'descuento' => null, 'no_show' => null,
                'saldo' => '168000.00', 'fecha_cobro' => $this->fecha('+5 days'), 'puede_pagar_saldo' => false,
                'cancelacion' => null, 'alojamiento' => $alojamiento,
            ],
        ]], $respuesta->json());
    }

    //Sin reservas, la lista vacía; sin sesión 401; un operador no tiene reservas (403)
    public function testMisReservasSinReservasYPermisos(): void
    {
        $huesped = $this->crearUsuarioEnBase('huesped');
        $this->assertSame(['reservas' => []], $this->iniciarSesion($huesped['mail'])->get('/reservas/mias')->json());
        $this->assertSame(401, $this->cliente()->get('/reservas/mias')->codigo);
        $this->assertSame(403, $this->iniciarSesion($this->crearUsuarioEnBase('operador')['mail'])->get('/reservas/mias')->codigo);
    }

    // ---------- cancelar ----------

    //El huésped cancela su reserva pendiente y esas noches se pueden volver a reservar
    public function testCancelarLiberaLasNoches(): void
    {
        $unidadId = $this->unidadPublicada();
        $huesped = $this->conMailVerificado($this->crearUsuarioEnBase('huesped'));
        $cliente = $this->iniciarSesion($huesped['mail']);
        $reservaId = $cliente->post('/reservas/crear', $this->datosReserva($unidadId))->json()['reserva']['id'];

        $respuesta = $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Cancelaste la reserva: esas noches quedaron libres', $respuesta->json()['ok']);
        $this->assertSame('cancelada', $this->pdo()->query("SELECT estado FROM reservas WHERE id = $reservaId")->fetchColumn());
        $this->assertSame(201, $this->reservar($unidadId)->codigo);//las mismas fechas, otro huésped
    }

    //No se cancela dos veces, ni una estadía terminada (409; la confirmada con devolución está en CancelacionTest),
    //ni la de otro huésped o una que no existe (404)
    public function testQueNoSePuedeCancelar(): void
    {
        $unidadId = $this->unidadPublicada();
        $huesped = $this->crearUsuarioEnBase('huesped');
        $cliente = $this->iniciarSesion($huesped['mail']);
        $casos = [
            'Esa reserva ya estaba cancelada'                                => [409, $this->reservaEnBase($unidadId, $huesped['id'], $this->fecha('+10 days'), $this->fecha('+12 days'), 'cancelada')],
            'Esa estadía ya terminó'                                         => [409, $this->reservaEnBase($unidadId, $huesped['id'], $this->fecha('+20 days'), $this->fecha('+22 days'), 'finalizada')],
            'No encontramos esa reserva'                                     => [404, $this->reservaEnBase($unidadId, $this->crearUsuarioEnBase('huesped')['id'], $this->fecha('+30 days'), $this->fecha('+32 days'))],
        ];
        foreach ($casos as $mensaje => [$codigo, $reservaId]) {
            $respuesta = $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId]);
            $this->assertSame($codigo, $respuesta->codigo, $mensaje);
            $this->assertSame($mensaje, $respuesta->json()['error']);
        }
        foreach ([999999, 'abc', true, null] as $invalido) {
            $this->assertSame(404, $cliente->post('/reservas/cancelar', ['reserva_id' => $invalido])->codigo);
        }
        $this->assertSame(401, $this->cliente()->post('/reservas/cancelar', ['reserva_id' => 1])->codigo);
    }

    // ---------- La 25: no-show y extensión en las listas (fase 0) ----------

    //Un no-show (#67) no se cancela, se ve en "Mis reservas" y le aparece al propietario entre las de las últimas 48 hs
    //para darle una excepción. Una extensión paga (#70) no se cancela y dice qué reserva extiende.
    public function testNoShowYExtensionEnLasListas(): void
    {
        $unidadId = $this->unidadPublicada();
        $huesped = $this->crearUsuarioEnBase('huesped', ['nombre' => 'Juan']);
        $original = $this->reservaEnBase($unidadId, $huesped['id'], $this->fecha('-2 days'), $this->fecha('+1 day'), 'confirmada');
        $extension = $this->reservaEnBase($unidadId, $huesped['id'], $this->fecha('+1 day'), $this->fecha('+3 days'), 'confirmada');
        $this->pdo()->prepare('UPDATE reservas SET extiende_reserva_id = ? WHERE id = ?')->execute([$original, $extension]);
        $noShow = $this->reservaEnBase($unidadId, $huesped['id'], $this->fecha('+10 days'), $this->fecha('+12 days'), 'confirmada');
        $this->pdo()->prepare("UPDATE reservas SET estado = 'no_show', no_show_en = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id = ?")
            ->execute([$noShow]);

        //el huésped: ninguna de las dos se cancela
        $cliente = $this->iniciarSesion($huesped['mail']);
        $this->assertSame('Una extensión paga no se puede cancelar', $cliente->post('/reservas/cancelar', ['reserva_id' => $extension])->json()['error']);
        $this->assertSame('El huésped no se presentó: esa reserva ya está resuelta', $cliente->post('/reservas/cancelar', ['reserva_id' => $noShow])->json()['error']);

        $mias = array_column($cliente->get('/reservas/mias')->json()['reservas'], null, 'id');
        $this->assertSame($original, $mias[$extension]['extiende_reserva_id']);
        $this->assertFalse($mias[$extension]['puede_cancelar']);
        $this->assertNull($mias[$original]['extiende_reserva_id']);
        $this->assertSame('no_show', $mias[$noShow]['estado']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $mias[$noShow]['no_show']['en']);
        $this->assertNull($mias[$noShow]['no_show']['excepcion']);
        $this->assertNull($mias[$original]['no_show']);

        //el propietario: la extensión en las reservas, sin poder cancelarla; el no-show entre las de las últimas 48 hs
        $respuestaUnidad = $this->iniciarSesion($this->propietario['mail'])->get('/unidades/reservas', ['id' => $unidadId]);
        $this->assertSame(200, $respuestaUnidad->codigo, $respuestaUnidad->cuerpo);
        $unidad = $respuestaUnidad->json();
        $reservas = array_column($unidad['reservas'], null, 'id');
        $this->assertSame($original, $reservas[$extension]['extiende_reserva_id']);
        $this->assertFalse($reservas[$extension]['puede_cancelar']);
        $this->assertArrayNotHasKey($noShow, $reservas);//un no-show no ocupa noches
        $this->assertSame([$noShow], array_column($unidad['canceladas'], 'id'));
        $this->assertSame('no_show', $unidad['canceladas'][0]['motivo']);
        $this->assertNull($unidad['canceladas'][0]['cancelada_por']);
    }

    // ---------- reservas de una unidad ----------

    //El propietario y el administrador ven las en curso y próximas (pendientes y confirmadas), de la más cercana
    //a la más lejana, con el nombre del huésped. No aparecen las terminadas, las canceladas ni las de otra unidad.
    public function testReservasDeLaUnidad(): void
    {
        $unidadId = $this->unidadPublicada();
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);
        $huespedId = $this->crearUsuarioEnBase('huesped', ['nombre' => 'Juan'])['id'];
        $lejana = $this->reservaEnBase($unidadId, $huespedId, $this->fecha('+20 days'), $this->fecha('+22 days'), 'confirmada');
        $enCurso = $this->reservaEnBase($unidadId, $huespedId, $this->fecha('-2 days'), $this->fecha('+1 day'));
        $this->reservaEnBase($unidadId, $huespedId, $this->fecha('-10 days'), $this->fecha('-1 day'), 'confirmada');//terminó
        $this->reservaEnBase($unidadId, $huespedId, $this->fecha('+5 days'), $this->fecha('+7 days'), 'cancelada');
        $this->reservaEnBase($this->unidadPublicada(), $huespedId, $this->fecha('+5 days'), $this->fecha('+7 days'));//otra unidad

        foreach ([$this->propietario['mail'], $administrador['mail']] as $mail) {
            $respuesta = $this->iniciarSesion($mail)->get('/unidades/reservas', ['id' => $unidadId]);

            $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
            $this->assertSame([$enCurso, $lejana], array_column($respuesta->json()['reservas'], 'id'));
            $esPropietario = $mail === $this->propietario['mail'];
            $this->assertSame([
                'id' => $lejana, 'estado' => 'confirmada', 'ingreso' => $this->fecha('+20 days'), 'egreso' => $this->fecha('+22 days'),
                'noches' => 2, 'personas' => 2, 'importe_total' => '210000.00', 'importe_sena' => '42000.00', 'huesped' => 'Juan',
                'extiende_reserva_id' => null,//no es una extensión (#70)
                'puede_cancelar' => $esPropietario,//solo el propietario cancela (#66)
            ], $respuesta->json()['reservas'][1]);
            $this->assertSame([], $respuesta->json()['canceladas']);
        }
    }

    //Otro propietario o un huésped no ven las reservas de la unidad (404); sin sesión 401
    public function testReservasDeLaUnidadSoloParaQuienLaGestiona(): void
    {
        $unidadId = $this->unidadPublicada();

        $this->assertSame(401, $this->cliente()->get('/unidades/reservas', ['id' => $unidadId])->codigo);
        foreach ([$this->crearPropietarioEnBase(), $this->crearUsuarioEnBase('huesped')] as $usuario) {
            $this->assertSame(404, $this->iniciarSesion($usuario['mail'])->get('/unidades/reservas', ['id' => $unidadId])->codigo);
        }
    }

    // ---------- permisos ----------

    //Sin sesión 401; sin el mail verificado 403; administrador y operador no reservan (403)
    public function testQuienPuedeReservar(): void
    {
        $datos = $this->datosReserva($this->unidadPublicada());

        $this->assertSame(401, $this->cliente()->post('/reservas/crear', $datos)->codigo);

        $sinVerificar = $this->iniciarSesion($this->crearUsuarioEnBase('huesped')['mail'])->post('/reservas/crear', $datos);
        $this->assertSame(403, $sinVerificar->codigo);
        $this->assertSame('Tenés que verificar tu mail para hacer esto', $sinVerificar->json()['error']);

        foreach (['administrador' => $this->crearAdministradorEnBase(), 'operador' => $this->crearUsuarioEnBase('operador')] as $rol => $usuario) {
            $this->conMailVerificado($usuario);
            $this->assertSame(403, $this->iniciarSesion($usuario['mail'])->post('/reservas/crear', $datos)->codigo, $rol);
        }
    }
}
