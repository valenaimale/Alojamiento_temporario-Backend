<?php
namespace Tests\Integracion;

final class EstadiasYLibroTest extends CasoDeIntegracion
{
    private function escenario(string $ingreso = 'today', string $estado = 'confirmada'): array
    {
        $operador = $this->crearUsuarioEnBase('operador');
        $titular = $this->crearUsuarioEnBase('huesped');
        $propietario = $this->crearPropietarioEnBase();
        $admin = $this->crearAdministradorEnBase();
        $unidad = $this->crearUnidadEnBase($propietario['id'], ['operador_id' => $operador['id']]);
        $this->asignarAdministradorEnBase($unidad, $admin['id']);
        $this->pdo()->prepare("INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado, importe_total, porcentaje_sena, importe_sena, monto_faltante, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15, politica_aceptada_en, no_show_en) VALUES (?, ?, ?, ?, 2, ?, 240000, 20, 48000, 0, 30, 50, 70, UTC_TIMESTAMP(), ?)")
            ->execute([$unidad, $titular['id'], $this->fecha($ingreso), $this->fecha('+10 days'), $estado, $estado === 'no_show' ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') : null]);
        $reserva = (int) $this->pdo()->lastInsertId();
        return compact('operador', 'titular', 'propietario', 'admin', 'unidad', 'reserva');
    }

    private function movimiento(int $id, string $tipo = 'ingreso', string $cuando = 'now'): array
    {
        return ['reserva_id' => $id, 'tipo' => $tipo, 'evento_id' => substr(bin2hex(random_bytes(8)), 0, 8) . '-1234-4567-8910-' . bin2hex(random_bytes(6)),
            'ocurrido_en' => (new \DateTimeImmutable($cuando, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'estado_unidad' => 'Todo en orden', 'notas' => 'Llaves entregadas'];
    }

    private function persona(array $cambios = []): array
    {
        return array_merge(['nombre' => 'Persona de prueba', 'tipo_documento' => 'DNI', 'documento' => '99.000.091', 'nacionalidad' => 'Argentina', 'fecha_nacimiento' => '1990-02-15', 'domicilio' => 'Dirección sintética 123'], $cambios);
    }

    public function testIngresoEgresoPersistenYReintentosSonIdempotentes(): void
    {
        $s = $this->escenario(); $c = $this->iniciarSesion($s['operador']['mail']);
        $in = $this->movimiento($s['reserva']);
        $a = $c->post('/operador/estadias/registrar', $in);
        $this->assertSame(200, $a->codigo, $a->cuerpo);
        $this->assertSame($in['ocurrido_en'], $a->json()['estadia']['ingreso_en']);
        $this->assertSame($a->json(), $c->post('/operador/estadias/registrar', $in)->json());
        $this->assertSame(409, $c->post('/operador/estadias/registrar', array_merge($in, ['notas' => 'Cambio']))->codigo);
        $out = $this->movimiento($s['reserva'], 'egreso');
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $out)->codigo);
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $out)->codigo);
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $in)->codigo);
        $r = $this->pdo()->query('SELECT * FROM reservas WHERE id = ' . $s['reserva'])->fetch();
        $this->assertSame('finalizada', $r['estado']);
        $this->assertSame('240000.00', $r['importe_total']);
        $this->assertSame($this->fecha('+10 days'), $r['fecha_egreso']);
        $agenda = $c->get('/operador/agenda');
        $this->assertSame(200, $agenda->codigo, $agenda->cuerpo);
        $this->assertSame($out['ocurrido_en'], $agenda->json()['reservas'][0]['estadia']['egreso_en']);
        $this->assertNull($agenda->json()['reservas'][0]['limite_ingreso']);
        $this->assertStringNotContainsString('importe', $agenda->cuerpo);
        $this->assertStringNotContainsString('documento', $agenda->cuerpo);
        // Un egreso temprano no libera por sorpresa las noches contratadas.
        $owner = $this->iniciarSesion($s['propietario']['mail']);
        $bloqueo = $owner->post('/unidades/bloqueos/crear', ['unidad_id' => $s['unidad'], 'fecha_desde' => $this->fecha('+1 day'), 'fecha_hasta' => $this->fecha('+2 days'), 'motivo' => 'uso_personal']);
        $this->assertSame(409, $bloqueo->codigo, $bloqueo->cuerpo);
        $this->assertStringContainsString('reserva', $bloqueo->cuerpo);
    }

    public function testPermisosYTransicionesInvalidasNoCreanEstadias(): void
    {
        $s = $this->escenario(); $in = $this->movimiento($s['reserva']);
        $this->assertSame(401, $this->cliente()->post('/operador/estadias/registrar', $in)->codigo);
        $this->assertSame(403, $this->iniciarSesion($s['titular']['mail'])->post('/operador/estadias/registrar', $in)->codigo);
        $otro = $this->crearUsuarioEnBase('operador');
        $this->assertSame(404, $this->iniciarSesion($otro['mail'])->post('/operador/estadias/registrar', $in)->codigo);
        $c = $this->iniciarSesion($s['operador']['mail']);
        $this->assertSame(409, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'egreso'))->codigo);
        $this->assertSame(409, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'ingreso', '-2 days'))->codigo);
        $this->assertSame(422, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'ingreso', '+1 day'))->codigo);
        $this->assertSame(422, $c->post('/operador/estadias/registrar', array_merge($in, ['notas' => str_repeat('á', 1001)]))->codigo);
        $this->pdo()->prepare("UPDATE reservas SET estado = 'pendiente' WHERE id = ?")->execute([$s['reserva']]);
        $this->assertSame(409, $c->post('/operador/estadias/registrar', $in)->codigo);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM estadias WHERE reserva_id = ' . $s['reserva'])->fetchColumn());
    }

    public function testIngresoAtrasadoYAgendaNoOcultanEstadiaAbierta(): void
    {
        $s = $this->escenario('-5 days'); $c = $this->iniciarSesion($s['operador']['mail']);
        $in = $this->movimiento($s['reserva'], 'ingreso', '-4 days');
        $this->registrarIngresoEnBase($s['reserva'], $in['ocurrido_en'], $s['operador']['id']);
        $this->pdo()->prepare('UPDATE reservas SET fecha_egreso = ? WHERE id = ?')->execute([$this->fecha('-1 day'), $s['reserva']]);
        $r = $c->get('/operador/agenda')->json()['reservas'][0];
        $this->assertTrue($r['puede_registrar_egreso']);
        $this->assertFalse($r['puede_registrar_ingreso']);
        $this->assertSame(409, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'egreso', '-5 days'))->codigo);
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'egreso'))->codigo);
    }

    private function registrarIngresoEnBase(int $reserva, string $fecha, int $operador): void
    {
        $this->pdo()->prepare('INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)')
            ->execute([$reserva, (new \DateTimeImmutable($fecha))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $operador, $this->movimiento($reserva)['evento_id']]);
    }

    public function testIngresoExigeSaldoPagoYPermiteReintentarDespues(): void
    {
        $s = $this->escenario(); $c = $this->iniciarSesion($s['operador']['mail']);
        $in = $this->movimiento($s['reserva']);
        foreach (['192000.00', '0.01', null] as $saldo) {
            $this->pdo()->prepare('UPDATE reservas SET monto_faltante = ? WHERE id = ?')->execute([$saldo, $s['reserva']]);
            $r = $c->post('/operador/estadias/registrar', $in);
            $this->assertSame(409, $r->codigo, $r->cuerpo);
            $this->assertSame($saldo === null ? 'SALDO_NO_INFORMADO' : 'SALDO_PENDIENTE', $r->json()['codigo']);
        }
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM estadias WHERE reserva_id = ' . $s['reserva'])->fetchColumn());
        $this->pdo()->prepare('UPDATE reservas SET monto_faltante = 0 WHERE id = ?')->execute([$s['reserva']]);
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $in)->codigo);
        $this->pdo()->prepare('UPDATE reservas SET monto_faltante = 1 WHERE id = ?')->execute([$s['reserva']]);
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $in)->codigo);
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'egreso'))->codigo);
    }

    public function testIngresoAcepta2359HorasPeroRechaza24HorasDesdeElCheckIn(): void
    {
        // El límite queda unos minutos en el futuro; ambos eventos están dentro de la tolerancia del dispositivo.
        $limite = new \DateTimeImmutable('+3 minutes', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $limite = $limite->setTime((int) $limite->format('H'), (int) $limite->format('i'));
        foreach ([-60 => 200, 0 => 409] as $segundos => $esperado) {
            $s = $this->escenario($limite->modify('-1 day')->format('Y-m-d'));
            $this->pdo()->prepare('UPDATE reservas SET fecha_egreso = ? WHERE id = ?')->execute([$limite->format('Y-m-d'), $s['reserva']]);
            $this->pdo()->prepare('UPDATE unidades SET hora_check_in = ? WHERE id = ?')->execute([$limite->format('H:i'), $s['unidad']]);
            $evento = $this->movimiento($s['reserva'], 'ingreso', $limite->modify("$segundos seconds")->format(DATE_ATOM));
            $c = $this->iniciarSesion($s['operador']['mail']);
            $agenda = $c->get('/operador/agenda');
            $this->assertSame(200, $agenda->codigo, $agenda->cuerpo);
            $this->assertSame($limite->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $agenda->json()['reservas'][0]['limite_ingreso']);
            $this->assertTrue($agenda->json()['reservas'][0]['puede_registrar_ingreso']);
            $r = $c->post('/operador/estadias/registrar', $evento);
            $this->assertSame($esperado, $r->codigo, $r->cuerpo);
            if ($esperado === 409) {
                $this->assertSame('PLAZO_INGRESO_VENCIDO', $r->json()['codigo']);
                $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM estadias WHERE reserva_id = ' . $s['reserva'])->fetchColumn());
            }
        }
    }

    public function testIngresoVencidoNoSePuedeRegistrarConFechaAnterior(): void
    {
        $s = $this->escenario('-3 days');
        $r = $this->iniciarSesion($s['operador']['mail'])->post('/operador/estadias/registrar',
            $this->movimiento($s['reserva'], 'ingreso', $this->fecha('-3 days') . 'T18:00:00Z'));
        $this->assertSame(409, $r->codigo, $r->cuerpo);
        $this->assertSame('PLAZO_INGRESO_VENCIDO', $r->json()['codigo']);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM estadias WHERE reserva_id = ' . $s['reserva'])->fetchColumn());
    }

    public function testNoShowNuncaPermiteRegistrarIngreso(): void
    {
        $s = $this->escenario('today', 'no_show');
        $r = $this->iniciarSesion($s['operador']['mail'])->post('/operador/estadias/registrar', $this->movimiento($s['reserva']));
        $this->assertSame(409, $r->codigo, $r->cuerpo);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM estadias WHERE reserva_id = ' . $s['reserva'])->fetchColumn());
    }

    public function testPropietarioYAdministradorRelacionadosPuedenRegistrar(): void
    {
        foreach (['propietario', 'admin'] as $rol) {
            $s = $this->escenario();
            $otro = $rol === 'propietario' ? $this->crearPropietarioEnBase() : $this->crearAdministradorEnBase();
            $evento = $this->movimiento($s['reserva']);
            $this->assertSame(404, $this->iniciarSesion($otro['mail'])->post('/operador/estadias/registrar', $evento)->codigo);
            $r = $this->iniciarSesion($s[$rol]['mail'])->post('/operador/estadias/registrar', $evento);
            $this->assertSame(200, $r->codigo, $r->cuerpo);
        }
    }

    public function testEgresoFinalizaOriginalYExtensionesConfirmadasEnUnSoloMovimiento(): void
    {
        $s = $this->escenario('-3 days');
        $extensiones = [];
        try {
            $this->pdo()->prepare('UPDATE reservas SET fecha_egreso = ? WHERE id = ?')->execute([$this->fecha('-1 day'), $s['reserva']]);
            $this->registrarIngresoEnBase($s['reserva'], $this->fecha('-3 days') . 'T18:00:00Z', $s['operador']['id']);
            foreach (['confirmada', 'pendiente', 'expirada'] as $estado) {
                $this->pdo()->prepare("INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado, importe_total, porcentaje_sena, importe_sena, monto_faltante, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15, politica_aceptada_en, extiende_reserva_id) VALUES (?, ?, ?, ?, 2, ?, 60000, 100, 60000, 0, 30, 50, 70, UTC_TIMESTAMP(), ?)")
                    ->execute([$s['unidad'], $s['titular']['id'], $this->fecha('-1 day'), $this->fecha(), $estado, $s['reserva']]);
                $extensiones[$estado] = (int) $this->pdo()->lastInsertId();
            }
            $c = $this->iniciarSesion($s['operador']['mail']);
            $this->assertSame(409, $c->post('/operador/estadias/registrar', $this->movimiento($extensiones['confirmada']))->codigo);
            $agenda = $c->get('/operador/agenda')->json()['reservas'];
            $this->assertCount(1, $agenda);
            $this->assertSame($this->fecha(), $agenda[0]['egreso']);
            $this->assertSame($this->fecha('-1 day'), $agenda[0]['egreso_original']);
            $this->assertSame([$extensiones['confirmada']], array_column($agenda[0]['extensiones'], 'id'));
            $evento = $this->movimiento($s['reserva'], 'egreso');
            $r = $c->post('/operador/estadias/registrar', $evento);
            $this->assertSame(200, $r->codigo, $r->cuerpo);
            $this->assertSame($r->json(), $c->post('/operador/estadias/registrar', $evento)->json());
            foreach ([$s['reserva'], $extensiones['confirmada']] as $id) {
                $this->assertSame('finalizada', $this->pdo()->query("SELECT estado FROM reservas WHERE id = $id")->fetchColumn());
            }
            foreach (['pendiente', 'expirada'] as $estado) {
                $this->assertSame($estado, $this->pdo()->query('SELECT estado FROM reservas WHERE id = ' . $extensiones[$estado])->fetchColumn());
            }
            $this->assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM estadias WHERE reserva_id IN (' . implode(', ', [$s['reserva'], ...array_values($extensiones)]) . ')')->fetchColumn());
        } finally {
            // La base se comparte con otros tests; estos vínculos pertenecen solo a este escenario.
            $this->pdo()->prepare('DELETE FROM estadias WHERE reserva_id = ?')->execute([$s['reserva']]);
            foreach ($extensiones as $id) {
                $this->pdo()->prepare('DELETE FROM reservas WHERE id = ?')->execute([$id]);
            }
            $this->pdo()->prepare('DELETE FROM reservas WHERE id = ?')->execute([$s['reserva']]);
        }
    }

    public function testHorarioDeCheckInSeValidaPersisteYPreservaAlEditar(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $c = $this->iniciarSesion($propietario['mail']);
        $datos = ['nombre' => 'Casa con horario', 'direccion' => 'Calle 123', 'capacidad' => 4, 'localidad_id' => 1];
        foreach (['24:00', '9:00', '12:60', '14:00:00', null, ['14:00']] as $hora) {
            $r = $c->post('/unidades/crear', $datos + ['hora_check_in' => $hora]);
            $this->assertSame(422, $r->codigo, $r->cuerpo);
        }
        $r = $c->post('/unidades/crear', $datos);
        $this->assertSame(201, $r->codigo, $r->cuerpo);
        $id = $r->json()['id'];
        $this->assertSame('14:00', $c->get('/unidades/datos', ['id' => $id])->json()['unidad']['hora_check_in']);
        $r = $c->post('/unidades/editar', $datos + ['unidad_id' => $id, 'hora_check_in' => '18:30']);
        $this->assertSame(200, $r->codigo, $r->cuerpo);
        $this->assertSame('18:30', $c->get('/unidades/datos', ['id' => $id])->json()['unidad']['hora_check_in']);
        $this->assertSame(200, $c->post('/unidades/editar', $datos + ['unidad_id' => $id])->codigo);
        $this->assertSame('18:30', $c->get('/unidades/datos', ['id' => $id])->json()['unidad']['hora_check_in']);
    }

    public function testLibroSeGuardaParcialYCompletoConControlDeVersion(): void
    {
        $s = $this->escenario('+2 days'); $c = $this->iniciarSesion($s['titular']['mail']);
        $d = ['reserva_id' => $s['reserva'], 'version' => 0, 'huespedes' => [$this->persona()]];
        $r = $c->post('/reservas/huespedes', $d); $this->assertSame(200, $r->codigo, $r->cuerpo);
        $this->assertFalse($r->json()['completo']);
        $this->assertSame(409, $c->post('/reservas/huespedes', $d)->codigo);
        $d['version'] = 1; $d['huespedes'][] = $this->persona(['documento' => 'AA123456', 'tipo_documento' => 'Pasaporte', 'nombre' => 'Acompañante']);
        $r = $c->post('/reservas/huespedes', $d); $this->assertSame(200, $r->codigo, $r->cuerpo);
        $this->assertTrue($r->json()['completo']);
        $libro = $c->get('/reservas/huespedes', ['reserva_id' => $s['reserva']])->json();
        $this->assertTrue($libro['reserva']['puede_editar']);
        $this->assertSame('99000091', $libro['huespedes'][0]['documento']);
        $this->assertTrue($libro['huespedes'][0]['es_titular']);
        $this->assertFalse($libro['huespedes'][1]['es_titular']);
        $this->assertSame(2, $libro['reserva']['version']);
    }

    public function testLibroPrivadoSoloTitularEscribeYRelacionadosLeenExportan(): void
    {
        $s = $this->escenario('+2 days');
        $d = ['reserva_id' => $s['reserva'], 'version' => 0, 'huespedes' => [$this->persona(['nombre' => '=SUM(1;2)'])]];
        $this->assertSame(200, $this->iniciarSesion($s['titular']['mail'])->post('/reservas/huespedes', $d)->codigo);
        foreach (['propietario', 'admin'] as $rol) {
            $c = $this->iniciarSesion($s[$rol]['mail']);
            $r = $c->get('/reservas/huespedes', ['reserva_id' => $s['reserva']]);
            $this->assertSame(200, $r->codigo, $r->cuerpo);
            $this->assertFalse($r->json()['reserva']['puede_editar']);
            $this->assertContains($c->post('/reservas/huespedes', $d)->codigo, [403, 404]);
            $csv = $c->get('/reservas/huespedes/exportar', ['reserva_id' => $s['reserva']]);
            $this->assertSame(200, $csv->codigo);
            $this->assertStringContainsString("'=SUM(1;2)", $csv->cuerpo);
            $lista = $c->get('/unidades/libro', ['unidad_id' => $s['unidad'], 'mes' => substr($this->fecha('+2 days'), 0, 7)]);
            $this->assertSame(200, $lista->codigo, $lista->cuerpo);
            $this->assertSame(1, $lista->json()['reservas'][0]['cargados']);
        }
        foreach ([$s['operador'], $this->crearUsuarioEnBase('huesped'), $this->crearAdministradorEnBase(), $this->crearPropietarioEnBase(), $this->crearUsuarioEnBase('backoffice')] as $u) {
            $c = $this->iniciarSesion($u['mail']);
            foreach (['/reservas/huespedes', '/reservas/huespedes/exportar'] as $ruta) $this->assertSame(404, $c->get($ruta, ['reserva_id' => $s['reserva']])->codigo);
            $this->assertContains($c->get('/unidades/libro', ['unidad_id' => $s['unidad'], 'mes' => substr($this->fecha('+2 days'), 0, 7)])->codigo, [403, 404]);
        }
        $this->pdo()->prepare('DELETE FROM administraciones WHERE unidad_id = ?')->execute([$s['unidad']]);
        $this->assertSame(404, $this->iniciarSesion($s['admin']['mail'])->get('/reservas/huespedes', ['reserva_id' => $s['reserva']])->codigo);
    }

    public function testLibroRechazaDatosInvalidosSinBorrarLoGuardado(): void
    {
        $s = $this->escenario('+2 days'); $c = $this->iniciarSesion($s['titular']['mail']);
        $base = ['reserva_id' => $s['reserva'], 'version' => 0, 'huespedes' => [$this->persona()]];
        $this->assertSame(200, $c->post('/reservas/huespedes', $base)->codigo);
        $base['version'] = 1;
        foreach ([[], [$this->persona(), $this->persona()], [$this->persona(['nombre' => ['mal']])], [$this->persona(['fecha_nacimiento' => '2001-02-30'])], [$this->persona(['documento' => '123'])], [$this->persona(['tipo_documento' => 'inexistente'])], [$this->persona(['nombre' => "A\nB"])]] as $filas) {
            $this->assertSame(422, $c->post('/reservas/huespedes', array_merge($base, ['huespedes' => $filas]))->codigo);
        }
        $this->assertSame(1, $c->get('/reservas/huespedes', ['reserva_id' => $s['reserva']])->json()['reserva']['version']);
        $this->pdo()->prepare('UPDATE reservas SET fecha_ingreso = ? WHERE id = ?')->execute([$this->fecha(), $s['reserva']]);
        $this->assertSame(409, $c->post('/reservas/huespedes', $base)->codigo);
        $this->assertFalse($c->get('/reservas/huespedes', ['reserva_id' => $s['reserva']])->json()['reserva']['puede_editar']);
    }
}
