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
        $this->pdo()->prepare("INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado, importe_total, porcentaje_sena, importe_sena, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15, politica_aceptada_en) VALUES (?, ?, ?, ?, 2, ?, 240000, 20, 48000, 30, 50, 70, UTC_TIMESTAMP())")
            ->execute([$unidad, $titular['id'], $this->fecha($ingreso), $this->fecha('+10 days'), $estado]);
        $reserva = (int) $this->pdo()->lastInsertId();
        return compact('operador', 'titular', 'propietario', 'admin', 'unidad', 'reserva');
    }

    private function movimiento(int $id, string $tipo = 'ingreso', string $cuando = 'now'): array
    {
        return ['reserva_id' => $id, 'tipo' => $tipo, 'evento_id' => substr(bin2hex(random_bytes(8)), 0, 8) . '-1234-4567-8910-' . bin2hex(random_bytes(6)),
            'ocurrido_en' => (new \DateTimeImmutable($cuando, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
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
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $in)->codigo);
        $this->pdo()->prepare('UPDATE reservas SET fecha_egreso = ? WHERE id = ?')->execute([$this->fecha('-1 day'), $s['reserva']]);
        $r = $c->get('/operador/agenda')->json()['reservas'][0];
        $this->assertTrue($r['puede_registrar_egreso']);
        $this->assertFalse($r['puede_registrar_ingreso']);
        $this->assertSame(409, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'egreso', '-5 days'))->codigo);
        $this->assertSame(200, $c->post('/operador/estadias/registrar', $this->movimiento($s['reserva'], 'egreso'))->codigo);
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
