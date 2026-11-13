<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//Extender la estadía (US7.1, decisión #70): GET /reservas/extension cotiza, POST /reservas/extender crea la reserva de
//extensión pendiente, y se paga entera como la seña de esa reserva (GET /pagos/sena y POST /pagos/confirmar).
//Usa el verificador simulado: un hash que no termina en "dead" ni en "0000" se confirma.
final class ExtensionesTest extends CasoDeIntegracion
{
    private array $propietario;
    private int $unidadId;
    private array $huesped;
    private ClienteApi $cliente;
    private int $reservaId;

    //Antes de cada test: una unidad publicada con $70.000 por noche y una estadía en curso de 4 noches
    //(ingresó hace 2 días, sale en 2), con todo pagado y el ingreso registrado
    protected function setUp(): void
    {
        $this->propietario = $this->crearPropietarioEnBase();
        $this->unidadId = $this->crearUnidadEnBase($this->propietario['id'], [
            'nombre' => 'Cabaña del Lago', 'estado_publicacion' => 'publicada', 'capacidad' => 4,
        ]);
        $this->agregarFotoEnBase($this->unidadId);
        $this->cargarPreciosEnBase($this->unidadId, $this->fecha('-5 days'), $this->fecha('+60 days'), 70000, $this->propietario['id']);

        [$this->huesped, $this->cliente] = $this->huespedConSesion();
        $this->reservaId = $this->reservaEnBase($this->huesped['id'], '-2 days', '+2 days');
        $this->registrarIngresoEnBase($this->reservaId);
    }

    //Después de cada test: borra las extensiones de la unidad (y lo que cuelga de ellas). La base se comparte con otros
    //tests: scripts/borrar-reservas-simuladas.php borra las originales (confirmadas sin una seña en la red real), y una
    //extensión que quedara apuntándoles se lo impediría por la clave foránea extiende_reserva_id.
    protected function tearDown(): void
    {
        $ids = $this->pdo()->query("SELECT id FROM reservas WHERE unidad_id = {$this->unidadId} AND extiende_reserva_id IS NOT NULL")
            ->fetchAll(\PDO::FETCH_COLUMN);
        if ($ids !== []) {
            $lista = implode(', ', array_map('intval', $ids));
            foreach (['pagos', 'liquidaciones', 'reservas'] as $tabla) {
                $this->pdo()->exec("DELETE FROM $tabla WHERE " . ($tabla === 'reservas' ? 'id' : 'reserva_id') . " IN ($lista)");
            }
        }
    }

    //Un huésped con el mail verificado y su sesión iniciada: [sus datos, el cliente]
    private function huespedConSesion(): array
    {
        $huesped = $this->crearUsuarioEnBase('huesped');
        $this->pdo()->prepare('UPDATE usuarios SET mail_verificado_en = UTC_TIMESTAMP() WHERE id = ?')->execute([$huesped['id']]);
        return [$huesped, $this->iniciarSesion($huesped['mail'])];
    }

    //Una reserva de la unidad guardada directo en la base, toda pagada. Devuelve su id.
    private function reservaEnBase(int $huespedId, string $ingreso, string $egreso, string $estado = 'confirmada'): int
    {
        $this->pdo()->prepare(
            "INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado, importe_total,
                                   porcentaje_sena, importe_sena, monto_faltante, retencion_mas_de_30, retencion_de_15_a_30,
                                   retencion_menos_de_15, politica_aceptada_en)
             VALUES (?, ?, ?, ?, 2, ?, 280000, 20, 56000, 0, 30, 50, 70, UTC_TIMESTAMP())"
        )->execute([$this->unidadId, $huespedId, $this->fecha($ingreso), $this->fecha($egreso), $estado]);
        return (int) $this->pdo()->lastInsertId();
    }

    //El ingreso de la reserva registrado hace un día (lo registra el propietario)
    private function registrarIngresoEnBase(int $reservaId): void
    {
        $this->pdo()->prepare(
            'INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id)
             VALUES (?, UTC_TIMESTAMP() - INTERVAL 1 DAY, UTC_TIMESTAMP(), ?, UUID())'
        )->execute([$reservaId, $this->propietario['id']]);
    }

    //Un hash de transacción válido y único (los tests comparten la base: nunca se repite) que el simulado confirma
    private function hash(): string
    {
        return '0x' . bin2hex(random_bytes(31)) . 'a1';
    }

    private function cotizar(int $noches, ?ClienteApi $cliente = null, ?int $reservaId = null)
    {
        return ($cliente ?? $this->cliente)->get('/reservas/extension', ['reserva_id' => $reservaId ?? $this->reservaId, 'noches' => $noches]);
    }

    private function extender(int $noches, ?ClienteApi $cliente = null, ?int $reservaId = null)
    {
        return ($cliente ?? $this->cliente)->post('/reservas/extender', ['reserva_id' => $reservaId ?? $this->reservaId, 'noches' => $noches]);
    }

    //Crea una extensión y devuelve su id
    private function crearExtension(int $noches): int
    {
        $respuesta = $this->extender($noches);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['reserva']['id'];
    }

    private function filaReserva(int $reservaId): array
    {
        return $this->pdo()->query("SELECT * FROM reservas WHERE id = $reservaId")->fetch();
    }

    private function cantidadDeReservas(): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM reservas WHERE unidad_id = {$this->unidadId}")->fetchColumn();
    }

    // ---------- cotizar ----------

    //La cotización empieza en la salida actual, cobra cada noche y no guarda nada
    public function testCotizaSinGuardar(): void
    {
        $antes = $this->cantidadDeReservas();

        $respuesta = $this->cotizar(2);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'reserva_id' => $this->reservaId, 'noches' => 2, 'ingreso' => $this->fecha('+2 days'), 'egreso' => $this->fecha('+4 days'),
            'importe_sin_descuento' => '140000.00', 'porcentaje_descuento' => '0.00', 'importe_total' => '140000.00',
        ], $respuesta->json());
        $this->assertSame($antes, $this->cantidadDeReservas());
    }

    //El descuento es el que alcanza la estadía completa: 4 noches de la original más 2 extra llegan al escalón de 6
    public function testElDescuentoEsElDeLaEstadiaCompleta(): void
    {
        $this->pdo()->prepare('INSERT INTO descuentos_estadia (unidad_id, noches_minimas, porcentaje) VALUES (?, 6, 10), (?, 10, 20)')
            ->execute([$this->unidadId, $this->unidadId]);

        $una = $this->cotizar(1)->json();
        $dos = $this->cotizar(2)->json();

        $this->assertSame(['70000.00', '0.00', '70000.00'], [$una['importe_sin_descuento'], $una['porcentaje_descuento'], $una['importe_total']]);
        $this->assertSame(['140000.00', '10.00', '126000.00'], [$dos['importe_sin_descuento'], $dos['porcentaje_descuento'], $dos['importe_total']]);
    }

    //No exige el mínimo de noches de la temporada
    public function testNoExigeElMinimoDeNoches(): void
    {
        $this->crearTemporadaEnBase($this->unidadId, 'alta', $this->fecha('-5 days'), $this->fecha('+30 days'), 7);

        $this->assertSame(200, $this->cotizar(1)->codigo);
    }

    //Mis reservas muestra el botón en la estadía en curso
    public function testMisReservasDiceQueSePuedeExtender(): void
    {
        $reservas = array_column($this->cliente->get('/reservas/mias')->json()['reservas'], null, 'id');

        $this->assertTrue($reservas[$this->reservaId]['puede_extender']);
    }

    // ---------- condiciones ----------

    //Sin el ingreso registrado, o con el egreso ya registrado, no se puede extender
    public function testSinIngresoOConEgresoNoSePuedeExtender(): void
    {
        $sinIngreso = $this->reservaEnBase($this->huesped['id'], '+20 days', '+25 days');
        foreach ([$this->cotizar(1, null, $sinIngreso), $this->extender(1, null, $sinIngreso)] as $respuesta) {
            $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
            $this->assertSame('Solo se puede extender una estadía en curso', $respuesta->json()['error']);
        }

        $this->pdo()->prepare('UPDATE estadias SET egreso_en = UTC_TIMESTAMP(), egreso_registrado_en = UTC_TIMESTAMP(), egreso_operador_id = ?, egreso_evento_id = UUID() WHERE reserva_id = ?')
            ->execute([$this->propietario['id'], $this->reservaId]);
        $this->assertSame(409, $this->cotizar(1)->codigo);
        $mias = array_column($this->cliente->get('/reservas/mias')->json()['reservas'], null, 'id');
        $this->assertFalse($mias[$this->reservaId]['puede_extender']);
        $this->assertFalse($mias[$sinIngreso]['puede_extender']);
    }

    //Si la salida ya pasó (nadie registró el egreso) no se extienden noches que ya pasaron
    public function testNoSeExtiendeUnaEstadiaCuyaSalidaYaPaso(): void
    {
        $this->pdo()->prepare('UPDATE reservas SET fecha_egreso = ? WHERE id = ?')->execute([$this->fecha('-1 day'), $this->reservaId]);

        $this->assertSame(409, $this->cotizar(1)->codigo);
    }

    //Sin lugar se rechaza: otra reserva o un bloqueo en las noches siguientes
    public function testSinLugarSeRechaza(): void
    {
        [$otro] = $this->huespedConSesion();
        $this->reservaEnBase($otro['id'], '+3 days', '+6 days');

        $this->assertSame(200, $this->cotizar(1)->codigo);//la noche +2 está libre
        $respuesta = $this->extender(2);//la +3 no
        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Hay noches ocupadas en esas fechas', $respuesta->json()['error']);

        $this->crearBloqueoEnBase($this->unidadId, $this->fecha('+2 days'), $this->fecha('+2 days'), $this->propietario['id']);
        $this->assertSame('Hay noches ocupadas en esas fechas', $this->cotizar(1)->json()['error']);
    }

    //Sin precio cargado en las noches extra se rechaza
    public function testSinPrecioSeRechaza(): void
    {
        $respuesta = $this->cotizar(30);//los precios llegan hasta +60: esto llega; se borran algunos

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->pdo()->prepare('DELETE FROM precio_noche WHERE unidad_id = ? AND fecha = ?')->execute([$this->unidadId, $this->fecha('+3 days')]);
        $this->assertSame('Hay noches sin precio en esas fechas', $this->cotizar(2)->json()['error']);
    }

    //No se puede extender la reserva de otro huésped: para él "no existe"
    public function testNoSePuedeExtenderLaReservaDeOtroHuesped(): void
    {
        [, $otro] = $this->huespedConSesion();
        $antes = $this->cantidadDeReservas();

        foreach ([$this->cotizar(1, $otro), $this->extender(1, $otro)] as $respuesta) {
            $this->assertSame(404, $respuesta->codigo, $respuesta->cuerpo);
            $this->assertSame('No encontramos esa reserva', $respuesta->json()['error']);
        }
        $this->assertSame($antes, $this->cantidadDeReservas());
    }

    //Datos inválidos y permisos
    public function testDatosInvalidosYPermisos(): void
    {
        foreach ([0, 31, 'dos', true] as $noches) {
            $respuesta = $this->cliente->post('/reservas/extender', ['reserva_id' => $this->reservaId, 'noches' => $noches]);
            $this->assertSame(422, $respuesta->codigo, json_encode($noches) . ': ' . $respuesta->cuerpo);
            $this->assertSame('Indicá cuántas noches más (de 1 a 30)', $respuesta->json()['error']);
        }
        $this->assertSame(404, $this->cliente->get('/reservas/extension', ['reserva_id' => 'abc', 'noches' => 1])->codigo);
        $this->assertSame(404, $this->cliente->get('/reservas/extension', ['reserva_id' => 999999999, 'noches' => 1])->codigo);
        $this->assertSame(401, $this->cliente()->get('/reservas/extension', ['reserva_id' => $this->reservaId, 'noches' => 1])->codigo);
        $operador = $this->crearUsuarioEnBase('operador');
        $this->assertSame(403, $this->iniciarSesion($operador['mail'])->post('/reservas/extender', ['reserva_id' => $this->reservaId, 'noches' => 1])->codigo);
    }

    // ---------- crear y pagar ----------

    //La extensión nace pendiente, vinculada, con toda la plata como seña y sin saldo
    public function testCreaLaExtensionPendiente(): void
    {
        $respuesta = $this->extender(2);

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertStringStartsWith('Pagá la extensión antes de las ', $respuesta->json()['ok']);
        $reserva = $respuesta->json()['reserva'];
        $this->assertSame(
            ['estado' => 'pendiente', 'extiende_reserva_id' => $this->reservaId, 'ingreso' => $this->fecha('+2 days'), 'egreso' => $this->fecha('+4 days'),
             'noches' => 2, 'importe_total' => '140000.00', 'importe_sena' => '140000.00'],
            array_intersect_key($reserva, array_flip(['estado', 'extiende_reserva_id', 'ingreso', 'egreso', 'noches', 'importe_total', 'importe_sena']))
        );
        //el mismo plazo que una seña (minutos_bloqueo_reserva: 10 desde la decisión #74)
        $plazo = (int) $this->pdo()->query("SELECT valor FROM parametros WHERE clave = 'minutos_bloqueo_reserva'")->fetchColumn();
        $this->assertEqualsWithDelta($plazo, (strtotime($reserva['vence_en']) - time()) / 60, 1);

        $fila = $this->filaReserva($reserva['id']);
        $this->assertSame(['100.00', '140000.00', '0.00', null, $this->huesped['id'], 2],
            [$fila['porcentaje_sena'], $fila['importe_sena'], $fila['monto_faltante'], $fila['fecha_cobro'], (int) $fila['huesped_id'], (int) $fila['cantidad_personas']]);

        //mientras espera el pago, guarda sus noches y no se puede pedir otra
        $this->assertSame('Ya tenés una extensión esperando el pago', $this->cotizar(1)->json()['error']);
        [, $otro] = $this->huespedConSesion();
        $reservaAjena = $otro->post('/reservas/crear', [
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha('+3 days'), 'egreso' => $this->fecha('+5 days'), 'personas' => 2, 'acepta_politica' => true,
        ]);
        $this->assertSame(409, $reservaAjena->codigo, $reservaAjena->cuerpo);
    }

    //El pago confirma la extensión: se paga entera como seña, sin saldo, no se cancela y la próxima sigue a esta
    public function testElPagoConfirmaLaExtension(): void
    {
        $extensionId = $this->crearExtension(2);

        $sena = $this->cliente->get('/pagos/sena', ['reserva_id' => $extensionId]);
        $this->assertSame(200, $sena->codigo, $sena->cuerpo);
        $this->assertSame(['140000.00', 'pagarSena'], [$sena->json()['monto_pesos'], $sena->json()['pago']['funcion']]);

        $pago = $this->cliente->post('/pagos/confirmar', ['reserva_id' => $extensionId, 'tx_hash' => $this->hash()]);
        $this->assertSame(200, $pago->codigo, $pago->cuerpo);
        $this->assertSame('Pagaste la extensión: tu estadía en Cabaña del Lago sigue hasta el '
            . implode('/', array_reverse(explode('-', $this->fecha('+4 days')))), $pago->json()['ok']);
        $this->assertSame('confirmada', $this->filaReserva($extensionId)['estado']);
        $this->assertSame('confirmada', $this->filaReserva($this->reservaId)['estado']);

        //no tiene saldo, no se paga dos veces y no se cancela
        $this->assertSame(409, $this->cliente->get('/pagos/saldo', ['reserva_id' => $extensionId])->codigo);
        $this->assertSame(409, $this->cliente->post('/pagos/confirmar', ['reserva_id' => $extensionId, 'tx_hash' => $this->hash()])->codigo);
        $mias = array_column($this->cliente->get('/reservas/mias')->json()['reservas'], null, 'id');
        $this->assertFalse($mias[$extensionId]['puede_cancelar']);
        $this->assertFalse($mias[$extensionId]['puede_extender']);
        $this->assertSame($this->reservaId, $mias[$extensionId]['extiende_reserva_id']);
        $this->assertSame(409, $this->cliente->post('/reservas/cancelar', ['reserva_id' => $extensionId])->codigo);

        //la siguiente extensión empieza en la nueva salida, con el descuento de las 4 + 2 + 1 noches
        $this->pdo()->prepare('INSERT INTO descuentos_estadia (unidad_id, noches_minimas, porcentaje) VALUES (?, 7, 5)')->execute([$this->unidadId]);
        $otra = $this->cotizar(1)->json();
        $this->assertSame([$this->fecha('+4 days'), $this->fecha('+5 days'), '5.00', '66500.00'],
            [$otra['ingreso'], $otra['egreso'], $otra['porcentaje_descuento'], $otra['importe_total']]);
    }

    //Una extensión vencida sin pagar libera las noches: no se paga y se puede volver a pedir
    public function testUnaExtensionVencidaLiberaLasNoches(): void
    {
        $extensionId = $this->crearExtension(2);
        $this->pdo()->prepare('UPDATE reservas SET bloqueo_expira_en = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$extensionId]);

        $pago = $this->cliente->post('/pagos/confirmar', ['reserva_id' => $extensionId, 'tx_hash' => $this->hash()]);
        $this->assertSame(409, $pago->codigo, $pago->cuerpo);
        $this->assertSame('expirada', $this->filaReserva($extensionId)['estado']);

        $otra = $this->cotizar(2);
        $this->assertSame(200, $otra->codigo, $otra->cuerpo);
        $this->assertSame($this->fecha('+2 days'), $otra->json()['ingreso']);
        $this->assertSame(201, $this->extender(2)->codigo);
    }

    //Si se registró el egreso antes de pagarla, la extensión ya no se paga
    public function testDespuesDelEgresoLaExtensionNoSePaga(): void
    {
        $extensionId = $this->crearExtension(1);
        $this->pdo()->prepare("UPDATE estadias SET egreso_en = UTC_TIMESTAMP(), egreso_registrado_en = UTC_TIMESTAMP(), egreso_operador_id = ?, egreso_evento_id = UUID() WHERE reserva_id = ?")
            ->execute([$this->propietario['id'], $this->reservaId]);
        $this->pdo()->prepare("UPDATE reservas SET estado = 'finalizada' WHERE id = ?")->execute([$this->reservaId]);

        $sena = $this->cliente->get('/pagos/sena', ['reserva_id' => $extensionId]);
        $this->assertSame(409, $sena->codigo, $sena->cuerpo);
        $this->assertSame('Esa estadía ya terminó: la extensión no se puede pagar', $sena->json()['error']);
        $this->assertSame(409, $this->cliente->post('/pagos/confirmar', ['reserva_id' => $extensionId, 'tx_hash' => $this->hash()])->codigo);
        $this->assertSame('pendiente', $this->filaReserva($extensionId)['estado']);
    }

    //Una extensión no se extiende: se extiende la original
    public function testUnaExtensionNoSeExtiende(): void
    {
        $extensionId = $this->crearExtension(1);

        $this->assertSame('Solo se puede extender una estadía en curso', $this->cotizar(1, null, $extensionId)->json()['error']);
    }
}
