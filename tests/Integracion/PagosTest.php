<?php

namespace Tests\Integracion;

use App\Validacion\CobroDelSaldo;
use Tests\Soporte\ClienteApi;

//El pago de la seña (L2-28) y el aviso si falla (L2-30): GET /pagos/sena y POST /pagos/confirmar.
//Usa el verificador simulado: el hash decide el resultado (termina en "dead" falla, en "0000" sigue pendiente,
//cualquier otro se confirma). También prueba el vencimiento de las reservas pendientes que no pagaron a tiempo.
final class PagosTest extends CasoDeIntegracion
{
    private const BILLETERA = '0x000000000000000000000000000000000000beef';//la de prueba del verificador simulado

    private int $unidadId;

    //Antes de cada test: una unidad publicada para 4 personas, con $70.000 por noche y 20 % de seña
    protected function setUp(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $this->unidadId = $this->crearUnidadEnBase($propietario['id'], [
            'nombre' => 'Cabaña del Mar', 'estado_publicacion' => 'publicada', 'capacidad' => 4,
        ]);
        $this->agregarFotoEnBase($this->unidadId);
        $this->cargarPreciosEnBase($this->unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $propietario['id']);
    }

    //Un hash de transacción válido que termina como pida el test ("1" se confirma, "dead" falla, "0000" queda pendiente)
    private function hash(string $final = '1'): string
    {
        static $numero = 0;
        $numero++;//cada llamada da un hash distinto: la misma transacción no puede pagar dos veces
        return '0x' . str_pad(dechex($numero) . 'a' . $final, 64, '0', STR_PAD_LEFT);
    }

    //Un huésped con el mail verificado y su sesión iniciada
    private function huesped(): ClienteApi
    {
        $huesped = $this->crearUsuarioEnBase('huesped');
        $this->pdo()->prepare('UPDATE usuarios SET mail_verificado_en = UTC_TIMESTAMP() WHERE id = ?')->execute([$huesped['id']]);
        return $this->iniciarSesion($huesped['mail']);
    }

    //Reserva 3 noches (del +10 al +13) y devuelve el id de la reserva
    private function reservar(ClienteApi $cliente, array $cambios = []): int
    {
        $respuesta = $cliente->post('/reservas/crear', array_merge([
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+13 days'),
            'personas' => 2, 'acepta_politica' => true,
        ], $cambios));
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['reserva']['id'];
    }

    //Le vence el plazo para pagar la seña a una reserva
    private function vencer(int $reservaId): void
    {
        $this->pdo()->prepare('UPDATE reservas SET bloqueo_expira_en = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$reservaId]);
    }

    private function filaReserva(int $reservaId): array
    {
        return $this->pdo()->query("SELECT * FROM reservas WHERE id = $reservaId")->fetch();
    }

    private function pagosDe(int $reservaId): array
    {
        return $this->pdo()->query("SELECT tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, estado FROM pagos WHERE reserva_id = $reservaId ORDER BY id")->fetchAll();
    }

    //Cómo queda guardado el pago de la seña de $42.000 de setUp (0,00042 ETH), con el verificador simulado
    private function filaPago(string $estado): array
    {
        return [
            'tipo' => 'sena', 'monto_pesos' => '42000.00', 'monto_wei' => '420000000000000', 'pesos_por_eth' => '100000000.00',
            'billetera' => self::BILLETERA, 'red' => 31337, 'estado' => $estado,
        ];
    }

    // ---------- reservar ----------

    //La reserva nace pendiente, con el plazo para pagar la seña (minutos_bloqueo_reserva: 15)
    public function testLaReservaNacePendienteConPlazoParaPagar(): void
    {
        $respuesta = $this->huesped()->post('/reservas/crear', [
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+13 days'),
            'personas' => 2, 'acepta_politica' => true,
        ]);

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $reserva = $respuesta->json()['reserva'];
        $this->assertSame('pendiente', $reserva['estado']);
        $this->assertStringStartsWith('Reservaste Cabaña del Mar: pagá la seña antes de las ', $respuesta->json()['ok']);
        $minutos = (strtotime($reserva['vence_en']) - time()) / 60;
        $this->assertEqualsWithDelta(15, $minutos, 1, "vence_en: {$reserva['vence_en']}");
    }

    //Con la seña mínima del 1 % (decisión #52), toda reserva nace pendiente y tiene una seña que pagar
    public function testConLaSenaMinimaLaReservaNacePendiente(): void
    {
        $this->pdo()->prepare('UPDATE unidades SET porcentaje_sena = 1 WHERE id = ?')->execute([$this->unidadId]);

        $reservaId = $this->reservar($this->huesped());

        $fila = $this->filaReserva($reservaId);
        $this->assertSame('pendiente', $fila['estado']);
        $this->assertNotNull($fila['bloqueo_expira_en']);
        $this->assertSame('207900.00', $fila['monto_faltante']);//$210.000 menos la seña de $2.100
    }

    //El saldo y su fecha de cobro quedan anotados al reservar: dias_cobro_saldo (5) antes del ingreso,
    //o hoy si se reserva con menos anticipación
    public function testGuardaElSaldoYLaFechaDeCobro(): void
    {
        $cliente = $this->huesped();
        $lejana = $this->filaReserva($this->reservar($cliente));
        $cercana = $this->filaReserva($this->reservar($cliente, ['ingreso' => $this->fecha('+2 days'), 'egreso' => $this->fecha('+5 days')]));

        $this->assertSame(['168000.00', $this->fecha('+5 days')], [$lejana['monto_faltante'], $lejana['fecha_cobro']]);
        $this->assertSame($this->fecha(), $cercana['fecha_cobro']);
    }

    // ---------- pagar la seña ----------

    //Consulta el monto, paga y la reserva queda confirmada, con el pago guardado
    public function testPagaLaSenaYConfirmaLaReserva(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservar($cliente);

        $sena = $cliente->get('/pagos/sena', ['reserva_id' => $reservaId]);
        $this->assertSame(200, $sena->codigo, $sena->cuerpo);
        //$42.000 con 1 ETH = $100.000.000: 0,00042 ETH = 420.000.000.000.000 wei. Red 31337: la del verificador simulado
        $this->assertSame(
            ['reserva_id' => $reservaId, 'tipo' => 'sena', 'monto_pesos' => '42000.00', 'monto_wei' => '420000000000000', 'pesos_por_eth' => '100000000', 'red' => 31337],
            array_intersect_key($sena->json(), array_flip(['reserva_id', 'tipo', 'monto_pesos', 'monto_wei', 'pesos_por_eth', 'red']))
        );
        $this->assertSame('pagarSena', $sena->json()['pago']['funcion']);
        $this->assertNotNull($sena->json()['vence_en']);

        $hash = $this->hash();
        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $hash]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'ok'      => 'Pagaste la seña: tu reserva en Cabaña del Mar está confirmada',
            'tipo'    => 'sena',
            'reserva' => ['id' => $reservaId, 'estado' => 'confirmada'],
            'pago'    => ['monto_pesos' => '42000.00', 'monto_wei' => '420000000000000', 'tx_hash' => $hash, 'red' => 31337],
        ], $respuesta->json());
        $fila = $this->filaReserva($reservaId);
        $this->assertSame('confirmada', $fila['estado']);
        $this->assertSame([$this->filaPago('confirmado')], $this->pagosDe($reservaId));

        //en "mis reservas" ya no se paga la seña, pero se puede cancelar (con devolución: CancelacionTest)
        $mia = $cliente->get('/reservas/mias')->json()['reservas'][0];
        $this->assertSame(['confirmada', null, false, true], [$mia['estado'], $mia['vence_en'], $mia['puede_pagar'], $mia['puede_cancelar']]);

        //pagar la seña otra vez: ya está pagada
        $otra = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash(), 'tipo' => 'sena']);
        $this->assertSame(409, $otra->codigo);
        $this->assertSame(['error' => 'La seña de esa reserva ya está pagada'], $otra->json());
        $this->assertSame(409, $cliente->get('/pagos/sena', ['reserva_id' => $reservaId])->codigo);
    }

    //Si la transacción falla, se guarda el intento, se avisa por qué (402) y la reserva sigue pendiente: puede reintentar
    public function testPagoFallidoAvisaYPermiteReintentar(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservar($cliente);

        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash('dead')]);

        $this->assertSame(402, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertStringStartsWith('No pudimos confirmar el pago de la seña: La transacción falló en la red. Podés pagar de nuevo hasta las ', $respuesta->json()['error']);
        $this->assertSame('La transacción falló en la red', $respuesta->json()['motivo']);
        $this->assertNotNull($respuesta->json()['vence_en']);
        $this->assertSame('pendiente', $this->filaReserva($reservaId)['estado']);
        $this->assertSame([$this->filaPago('fallido')], $this->pagosDe($reservaId));

        $reintento = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()]);
        $this->assertSame(200, $reintento->codigo, $reintento->cuerpo);
        $this->assertSame(['fallido', 'confirmado'], array_column($this->pagosDe($reservaId), 'estado'));
    }

    //Una transacción que todavía no está en un bloque: 202 y no se guarda nada (el front vuelve a preguntar)
    public function testTransaccionPendienteNoGuardaNada(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservar($cliente);
        $hash = $this->hash('0000');

        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $hash]);

        $this->assertSame(202, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertTrue($respuesta->json()['pendiente']);
        $this->assertSame([], $this->pagosDe($reservaId));
        $this->assertSame('pendiente', $this->filaReserva($reservaId)['estado']);
    }

    //La misma transacción no paga dos reservas, aunque venga en mayúsculas
    public function testLaMismaTransaccionNoPagaDosReservas(): void
    {
        $cliente = $this->huesped();
        $primera = $this->reservar($cliente);
        $segunda = $this->reservar($cliente, ['ingreso' => $this->fecha('+20 days'), 'egreso' => $this->fecha('+23 days')]);
        $hash = $this->hash();
        $this->assertSame(200, $cliente->post('/pagos/confirmar', ['reserva_id' => $primera, 'tx_hash' => $hash])->codigo);

        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $segunda, 'tx_hash' => '0x' . strtoupper(substr($hash, 2))]);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame(['error' => 'Esa transacción ya se usó para otro pago'], $respuesta->json());
        $this->assertSame('pendiente', $this->filaReserva($segunda)['estado']);
    }

    // ---------- pagar el saldo ----------

    //Reserva y paga la seña: queda confirmada, con $168.000 de saldo
    private function reservaConfirmada(ClienteApi $cliente, array $cambios = []): int
    {
        $reservaId = $this->reservar($cliente, $cambios);
        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        return $reservaId;
    }

    //Con la seña paga: consulta el saldo, lo paga y la reserva queda sin nada que pagar
    public function testPagaElSaldo(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservaConfirmada($cliente);

        $saldo = $cliente->get('/pagos/saldo', ['reserva_id' => $reservaId]);
        $this->assertSame(200, $saldo->codigo, $saldo->cuerpo);
        //$168.000 con 1 ETH = $100.000.000: 0,00168 ETH. Se cobra 5 días antes del ingreso (+10)
        $this->assertSame([
            'reserva_id' => $reservaId, 'tipo' => 'saldo', 'monto_pesos' => '168000.00', 'monto_wei' => '1680000000000000',
            'pesos_por_eth' => '100000000', 'red' => 31337, 'fecha_cobro' => $this->fecha('+5 days'), 'vencido' => false,
            'limite_pago' => $this->fecha('+8 days'),//3 días después del cobro, antes del ingreso (decisión #23)
            'pago' => ['contrato' => null, 'funcion' => 'pagarSaldo', 'simulado' => true],
        ], $saldo->json());
        $mia = $cliente->get('/reservas/mias')->json()['reservas'][0];
        $this->assertSame(['168000.00', true], [$mia['saldo'], $mia['puede_pagar_saldo']]);

        //sin "tipo": la reserva ya está confirmada, así que paga el saldo
        $hash = $this->hash();
        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $hash]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([
            'ok'      => 'Pagaste el saldo: tu reserva en Cabaña del Mar está paga',
            'tipo'    => 'saldo',
            'reserva' => ['id' => $reservaId, 'estado' => 'confirmada'],
            'pago'    => ['monto_pesos' => '168000.00', 'monto_wei' => '1680000000000000', 'tx_hash' => $hash, 'red' => 31337],
        ], $respuesta->json());
        $this->assertSame(['confirmada', '0.00'], [$this->filaReserva($reservaId)['estado'], $this->filaReserva($reservaId)['monto_faltante']]);
        $this->assertSame([
            $this->filaPago('confirmado'),
            array_replace($this->filaPago('confirmado'), ['tipo' => 'saldo', 'monto_pesos' => '168000.00', 'monto_wei' => '1680000000000000']),
        ], $this->pagosDe($reservaId));
        $mia = $cliente->get('/reservas/mias')->json()['reservas'][0];
        $this->assertSame(['0.00', false], [$mia['saldo'], $mia['puede_pagar_saldo']]);

        //pagarlo otra vez: ya está pagado
        $otra = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()]);
        $this->assertSame(['error' => 'El saldo de esa reserva ya está pagado'], $otra->json());
        $this->assertSame(409, $cliente->get('/pagos/saldo', ['reserva_id' => $reservaId])->codigo);
    }

    //RF-26: si el pago del saldo falla, se guarda el intento, se avisa por qué (402) y se puede reintentar
    public function testPagoDelSaldoFallidoAvisaYPermiteReintentar(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservaConfirmada($cliente);

        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash('dead'), 'tipo' => 'saldo']);

        $this->assertSame(402, $respuesta->codigo, $respuesta->cuerpo);
        //decisión #23: el plazo es 3 días después del cobro, o el ingreso si llega antes
        //con los días explícitos: sin ellos, limiteDePago lee el parámetro con la conexión de la app, no la de prueba
        $limite = CobroDelSaldo::limiteDePago($this->fecha('+5 days'), $this->filaReserva($reservaId)['fecha_ingreso'], 3);
        $dia = (new \DateTimeImmutable($limite))->format('d/m/Y');
        $this->assertSame([
            'error'       => "No pudimos confirmar el pago del saldo: La transacción falló en la red. Tenés tiempo hasta el $dia inclusive: si no, la reserva se cancela sola.",
            'tipo'        => 'saldo',
            'motivo'      => 'La transacción falló en la red',
            'fecha_cobro' => $this->fecha('+5 days'),
            'limite_pago' => $limite,
        ], $respuesta->json());
        $this->assertSame(['confirmada', '168000.00'], [$this->filaReserva($reservaId)['estado'], $this->filaReserva($reservaId)['monto_faltante']]);
        $this->assertSame(['confirmado', 'fallido'], array_column($this->pagosDe($reservaId), 'estado'));

        $this->assertSame(200, $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()])->codigo);
        $this->assertSame('0.00', $this->filaReserva($reservaId)['monto_faltante']);
    }

    //El saldo no se paga antes de la seña, ni en una reserva cancelada o terminada; "tipo" tiene que ser válido
    public function testCuandoNoSePagaElSaldo(): void
    {
        $cliente = $this->huesped();
        $pendiente = $this->reservar($cliente);

        $this->assertSame(['error' => 'Primero tenés que pagar la seña'], $cliente->get('/pagos/saldo', ['reserva_id' => $pendiente])->json());
        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $pendiente, 'tx_hash' => $this->hash(), 'tipo' => 'saldo']);
        $this->assertSame([409, 'pendiente'], [$respuesta->codigo, $this->filaReserva($pendiente)['estado']]);
        $this->assertSame([], $this->pagosDe($pendiente));
        $this->assertSame(422, $cliente->post('/pagos/confirmar', ['reserva_id' => $pendiente, 'tx_hash' => $this->hash(), 'tipo' => 'otro'])->codigo);

        foreach (['cancelada' => 'Esa reserva está cancelada', 'finalizada' => 'Esa estadía ya terminó'] as $estado => $error) {
            $reservaId = $this->reservaConfirmada($cliente, ['ingreso' => $this->fecha('+20 days'), 'egreso' => $this->fecha('+23 days')]);
            $this->pdo()->prepare('UPDATE reservas SET estado = ? WHERE id = ?')->execute([$estado, $reservaId]);
            $respuesta = $cliente->get('/pagos/saldo', ['reserva_id' => $reservaId]);
            $this->assertSame([409, ['error' => $error]], [$respuesta->codigo, $respuesta->json()], $estado);
        }
    }

    //Pasada la fecha de cobro el saldo se puede seguir pagando, pero figura vencido (RF-26)
    public function testElSaldoVencidoSeSiguePagando(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservaConfirmada($cliente);
        $this->pdo()->prepare('UPDATE reservas SET fecha_cobro = ? WHERE id = ?')->execute([$this->fecha('-1 day'), $reservaId]);

        $saldo = $cliente->get('/pagos/saldo', ['reserva_id' => $reservaId])->json();
        $limite = CobroDelSaldo::limiteDePago($this->fecha('-1 day'), $this->filaReserva($reservaId)['fecha_ingreso'], 3);
        $this->assertSame([true, $limite], [$saldo['vencido'], $saldo['limite_pago']]);
        $fallido = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash('dead')]);
        $dia = (new \DateTimeImmutable($limite))->format('d/m/Y');
        $this->assertStringEndsWith("Tenés tiempo hasta el $dia inclusive: si no, la reserva se cancela sola.", $fallido->json()['error']);
        $this->assertSame(200, $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()])->codigo);
    }

    // ---------- vencimiento ----------

    //Sin pagar a tiempo: no se puede pagar, se ve expirada, deja de ocupar sus noches y ya no se cancela
    public function testLaReservaVencidaSeLiberaYNoSePaga(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservar($cliente);
        $this->vencer($reservaId);

        $mia = $cliente->get('/reservas/mias')->json()['reservas'][0];
        $this->assertSame(['expirada', null, false, false], [$mia['estado'], $mia['vence_en'], $mia['puede_pagar'], $mia['puede_cancelar']]);
        $this->assertSame(409, $cliente->get('/pagos/sena', ['reserva_id' => $reservaId])->codigo);
        $this->assertSame(409, $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId])->codigo);

        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()]);
        $this->assertSame(409, $respuesta->codigo);
        $this->assertStringStartsWith('Se venció el plazo para pagar la seña', $respuesta->json()['error']);
        $this->assertSame('expirada', $this->filaReserva($reservaId)['estado']);//al tocarla queda anotada
        $this->assertSame([], $this->pagosDe($reservaId));

        //las noches quedaron libres: otro huésped reserva las mismas fechas
        $this->assertSame(201, $this->huesped()->post('/reservas/crear', [
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+13 days'),
            'personas' => 2, 'acepta_politica' => true,
        ])->codigo);
    }

    //Una pendiente vencida tampoco frena los bloqueos del propietario ni aparece en las reservas de la unidad
    public function testLaReservaVencidaNoOcupaParaElPropietario(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $this->pdo()->prepare('UPDATE unidades SET propietario_id = ? WHERE id = ?')->execute([$propietario['id'], $this->unidadId]);
        $reservaId = $this->reservar($this->huesped());
        $this->vencer($reservaId);
        $dueno = $this->iniciarSesion($propietario['mail']);

        $this->assertSame([], $dueno->get('/unidades/reservas', ['id' => $this->unidadId])->json()['reservas']);
        $bloqueo = $dueno->post('/unidades/bloqueos/crear', [
            'unidad_id' => $this->unidadId, 'fecha_desde' => $this->fecha('+10 days'), 'fecha_hasta' => $this->fecha('+12 days'), 'motivo' => 'reserva_externa',
        ]);
        $this->assertSame(201, $bloqueo->codigo, $bloqueo->cuerpo);
    }

    // ---------- permisos y datos ----------

    //Sin sesión 401, otro rol 403, la reserva de otro huésped "no existe" (404), un hash mal formado 422
    public function testPermisosYDatosInvalidos(): void
    {
        $reservaId = $this->reservar($this->huesped());
        $otro = $this->huesped();

        $this->assertSame(401, $this->cliente()->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()])->codigo);
        $this->assertSame(403, $this->iniciarSesion($this->crearUsuarioEnBase('operador')['mail'])->get('/pagos/sena', ['reserva_id' => $reservaId])->codigo);
        $this->assertSame(404, $otro->get('/pagos/sena', ['reserva_id' => $reservaId])->codigo);
        $this->assertSame(404, $otro->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash()])->codigo);
        $this->assertSame(404, $otro->get('/pagos/sena', ['reserva_id' => 'abc'])->codigo);
        foreach ([null, '', '0x123', '0x' . str_repeat('g', 64), 123, true] as $hash) {
            $respuesta = $otro->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $hash]);
            $this->assertSame(422, $respuesta->codigo, var_export($hash, true));
        }
        $this->assertSame('pendiente', $this->filaReserva($reservaId)['estado']);
    }
}
