<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;
use Tests\Soporte\EntornoDePrueba;

//Cancelar una reserva confirmada con devolución (L2-3, L2-18): GET /reservas/cancelacion y POST /reservas/cancelar.
//Se retiene el % de la seña del tramo (días hasta el cobro del saldo) y se devuelve el resto, más el saldo si se pagó.
//Usa el verificador y el firmador simulados: la devolución se da por confirmada al instante, salvo que la billetera
//que pagó termine en "dead" (el firmador simulado no le puede mandar nada).
final class CancelacionTest extends CasoDeIntegracion
{
    private const BILLETERA = '0x000000000000000000000000000000000000beef';//la de prueba del verificador simulado

    private int $unidadId;
    private array $propietario;

    //Antes de cada test: una unidad publicada, con $70.000 por noche, 20 % de seña y retenciones de 30 %, 50 % y 70 %
    protected function setUp(): void
    {
        $this->propietario = $this->crearPropietarioEnBase();
        $this->unidadId = $this->crearUnidadEnBase($this->propietario['id'], [
            'nombre' => 'Cabaña del Mar', 'estado_publicacion' => 'publicada', 'capacidad' => 4,
        ]);
        $this->agregarFotoEnBase($this->unidadId);
        $this->cargarPreciosEnBase($this->unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $this->propietario['id']);
    }

    //Un hash de transacción válido: el verificador simulado lo confirma
    private function hash(): string
    {
        static $numero = 0;
        $numero++;
        return '0x' . str_pad(dechex($numero) . 'c1', 64, '0', STR_PAD_LEFT);
    }

    //Un huésped con el mail verificado y su sesión iniciada
    private function huesped(): ClienteApi
    {
        $huesped = $this->crearUsuarioEnBase('huesped');
        $this->pdo()->prepare('UPDATE usuarios SET mail_verificado_en = UTC_TIMESTAMP() WHERE id = ?')->execute([$huesped['id']]);
        return $this->iniciarSesion($huesped['mail']);
    }

    //Reserva 3 noches ($210.000, seña $42.000, saldo $168.000) desde $dias días adelante. El saldo se cobra 5 días antes.
    private function reservar(ClienteApi $cliente, int $dias): int
    {
        $respuesta = $cliente->post('/reservas/crear', [
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha("+$dias days"), 'egreso' => $this->fecha('+' . ($dias + 3) . ' days'),
            'personas' => 2, 'acepta_politica' => true,
        ]);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['reserva']['id'];
    }

    //Paga la seña o el saldo con el verificador simulado
    private function pagar(ClienteApi $cliente, int $reservaId, string $tipo): void
    {
        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $this->hash(), 'tipo' => $tipo]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
    }

    //Reserva y paga la seña
    private function reservaConfirmada(ClienteApi $cliente, int $dias): int
    {
        $reservaId = $this->reservar($cliente, $dias);
        $this->pagar($cliente, $reservaId, 'sena');
        return $reservaId;
    }

    private function estadoDe(int $reservaId): string
    {
        return $this->pdo()->query("SELECT estado FROM reservas WHERE id = $reservaId")->fetchColumn();
    }

    private function devolucionesDe(int $reservaId): array
    {
        return $this->pdo()->query(
            "SELECT monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, confirmado_en IS NOT NULL AS con_fecha
             FROM pagos WHERE reserva_id = $reservaId AND tipo = 'devolucion'"
        )->fetchAll();
    }

    // ---------- con devolución ----------

    //A 45 días del cobro: ve cuánto le devuelven, cancela, se le devuelve el 70 % de la seña y las noches se liberan
    public function testVeCuantoLeDevuelvenYCancela(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservaConfirmada($cliente, 50);
        $devolucion = [
            'tramo' => 'mas_de_30', 'dias_hasta_cobro' => 45, 'porcentaje_retencion' => '30.00',
            'pagado' => ['sena' => '42000.00', 'saldo' => '0.00'],
            'retencion' => '12600.00', 'devolucion' => '29400.00', 'devolucion_wei' => '294000000000000',
        ];
        $this->assertTrue($cliente->get('/reservas/mias')->json()['reservas'][0]['puede_cancelar']);

        $vista = $cliente->get('/reservas/cancelacion', ['reserva_id' => $reservaId]);
        $this->assertSame(200, $vista->codigo, $vista->cuerpo);
        $this->assertSame(['reserva_id' => $reservaId, 'estado' => 'confirmada'] + $devolucion, $vista->json());
        $this->assertSame('confirmada', $this->estadoDe($reservaId));//ver no cambia nada

        $respuesta = $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $cuerpo = $respuesta->json();
        $this->assertSame('Cancelaste la reserva: esas noches quedaron libres. Te devolvimos $29.400,00 a tu billetera', $cuerpo['ok']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $cuerpo['devolucion']['tx_hash']);
        //la red de la devolución: con el firmador simulado es la 31337, así que el front no la enlaza en BaseScan
        $this->assertSame($devolucion + ['estado' => 'confirmado', 'tx_hash' => $cuerpo['devolucion']['tx_hash'], 'red' => 31337, 'simulado' => true], $cuerpo['devolucion']);
        $this->assertSame('cancelada', $this->estadoDe($reservaId));
        $this->assertSame([[
            'monto_pesos' => '29400.00', 'monto_wei' => '294000000000000', 'pesos_por_eth' => '100000000.00',
            'billetera' => self::BILLETERA, 'red' => 31337, 'tx_hash' => $cuerpo['devolucion']['tx_hash'], 'estado' => 'confirmado', 'con_fecha' => 1,
        ]], $this->devolucionesDe($reservaId));

        //las noches quedaron libres, y no se cancela dos veces
        $this->assertSame(201, $this->huesped()->post('/reservas/crear', [
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha('+50 days'), 'egreso' => $this->fecha('+53 days'),
            'personas' => 2, 'acepta_politica' => true,
        ])->codigo);
        $this->assertSame(['error' => 'Esa reserva ya estaba cancelada'], $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId])->json());
        $this->assertCount(1, $this->devolucionesDe($reservaId));
    }

    //Cuanto más cerca del cobro, más se retiene: de 15 a 30 días el 50 %, a menos de 15 el 70 %
    public function testLaRetencionDependeDelTramo(): void
    {
        $cliente = $this->huesped();
        foreach ([25 => ['de_15_a_30', 20, '21000.00', '21000.00'], 10 => ['menos_de_15', 5, '29400.00', '12600.00']] as $dias => $esperado) {
            $vista = $cliente->get('/reservas/cancelacion', ['reserva_id' => $this->reservaConfirmada($cliente, $dias)])->json();
            $this->assertSame($esperado, [$vista['tramo'], $vista['dias_hasta_cobro'], $vista['retencion'], $vista['devolucion']], "a $dias días");
        }
    }

    //Con el saldo pagado, el saldo se devuelve entero: solo se retiene parte de la seña
    public function testConElSaldoPagadoSeDevuelveEntero(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservaConfirmada($cliente, 10);
        $this->pagar($cliente, $reservaId, 'saldo');

        $respuesta = $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $devolucion = $respuesta->json()['devolucion'];
        $this->assertSame(
            [['sena' => '42000.00', 'saldo' => '168000.00'], '29400.00', '180600.00', '1806000000000000'],
            [$devolucion['pagado'], $devolucion['retencion'], $devolucion['devolucion'], $devolucion['devolucion_wei']]
        );
        $this->assertSame('180600.00', $this->devolucionesDe($reservaId)[0]['monto_pesos']);
    }

    //Si no se puede mandar la devolución, la reserva queda cancelada igual y la devolución pendiente, para reintentarla
    public function testSiLaRedFallaLaDevolucionQuedaPendiente(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservaConfirmada($cliente, 50);
        $billetera = '0x' . str_repeat('0', 36) . 'dead';//el firmador simulado no le puede mandar nada
        $this->pdo()->prepare('UPDATE pagos SET billetera = ? WHERE reserva_id = ?')->execute([$billetera, $reservaId]);

        $respuesta = $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Cancelaste la reserva: esas noches quedaron libres. La devolución de $29.400,00 está en proceso: te avisamos por mail', $respuesta->json()['ok']);
        $this->assertSame(['pendiente', null], [$respuesta->json()['devolucion']['estado'], $respuesta->json()['devolucion']['tx_hash']]);
        $this->assertSame('cancelada', $this->estadoDe($reservaId));
        $fila = $this->devolucionesDe($reservaId)[0];
        $this->assertSame([$billetera, null, 'pendiente', 0], [$fila['billetera'], $fila['tx_hash'], $fila['estado'], $fila['con_fecha']]);

        //el script de reintentos no toca las recién creadas (las puede estar mandando la cancelación)...
        $this->assertSame([0, "No hay devoluciones pendientes.\n"], $this->reintentarDevoluciones());
        //...y una vieja que sigue fallando queda pendiente (sale con 1)
        $this->pdo()->prepare("UPDATE pagos SET creado_en = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE reserva_id = ? AND tipo = 'devolucion'")->execute([$reservaId]);
        $this->assertSame(1, $this->reintentarDevoluciones()[0]);
        $this->assertSame('pendiente', $this->devolucionesDe($reservaId)[0]['estado']);

        //cuando la red vuelve, la manda
        $this->pdo()->prepare("UPDATE pagos SET billetera = ? WHERE reserva_id = ? AND tipo = 'devolucion'")->execute([self::BILLETERA, $reservaId]);
        [$codigo, $salida] = $this->reintentarDevoluciones();
        $this->assertSame(0, $codigo, $salida);
        $fila = $this->devolucionesDe($reservaId)[0];
        $this->assertSame(['confirmado', 1], [$fila['estado'], $fila['con_fecha']]);
        $this->assertStringContainsString("Reserva $reservaId: devolución de \$29400.00 mandada ({$fila['tx_hash']})", $salida);
    }

    //Corre scripts/reintentar-devoluciones.php contra la base de prueba. Devuelve [código de salida, lo que imprimió].
    private function reintentarDevoluciones(): array
    {
        return $this->correrScript('reintentar-devoluciones.php');
    }

    //Corre un script de scripts/ contra la base de prueba. Devuelve [código de salida, lo que imprimió].
    private function correrScript(string $script): array
    {
        $db = EntornoDePrueba::datosBase();
        $entorno = array_merge(getenv(), [
            'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_USER' => $db['user'], 'DB_PASS' => $db['pass'], 'DB_NAME' => $db['name'],
        ]);
        //los logs (stderr) van a un archivo temporal: con dos tuberías, si el script llena la de stderr (por ejemplo,
        //con los mails que fallan sin Mailpit) mientras el test lee la de stdout, los dos se quedan esperando
        $errores = tempnam(sys_get_temp_dir(), 'script');
        $proceso = proc_open([PHP_BINARY, RAIZ . '/scripts/' . $script], [1 => ['pipe', 'w'], 2 => ['file', $errores, 'w']], $tuberias, RAIZ, $entorno);
        $salida = stream_get_contents($tuberias[1]);
        $codigo = proc_close($proceso);
        @unlink($errores);
        return [$codigo, $salida];
    }

    // ---------- saldo vencido (decisión #23) ----------

    //Pasado el plazo del saldo (3 días después del cobro) sin pagarlo, el script la cancela sola con el tramo de
    //menos de 15 días: se retiene el 70 % de la seña ($29.400) y se devuelven $12.600. Las que están a tiempo,
    //las que pagaron el saldo y las que ya ingresaron no se tocan.
    public function testElSaldoVencidoCancelaLaReservaSola(): void
    {
        $cliente = $this->huesped();
        $vencida = $this->reservaConfirmada($cliente, 50);
        $aTiempo = $this->reservaConfirmada($cliente, 60);
        $pagada = $this->reservaConfirmada($cliente, 70);
        $this->pagar($cliente, $pagada, 'saldo');
        $ingresada = $this->reservaConfirmada($cliente, 80);
        $cambiarCobro = $this->pdo()->prepare('UPDATE reservas SET fecha_cobro = ? WHERE id = ?');
        $cambiarCobro->execute([$this->fecha('-4 days'), $vencida]);  //el plazo venció ayer
        $cambiarCobro->execute([$this->fecha('-3 days'), $aTiempo]);  //el plazo vence hoy: todavía puede pagar
        $cambiarCobro->execute([$this->fecha('-10 days'), $pagada]);
        $cambiarCobro->execute([$this->fecha('-10 days'), $ingresada]);
        $this->pdo()->prepare(
            'INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id)
             VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)'
        )->execute([$ingresada, $this->propietario['id'], bin2hex(random_bytes(18))]);

        [$codigo, $salida] = $this->correrScript('cancelar-saldos-vencidos.php');

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString("Reserva $vencida: cancelada", $salida);
        $this->assertSame(['cancelada', 'confirmada', 'confirmada', 'confirmada'], array_map([$this, 'estadoDe'], [$vencida, $aTiempo, $pagada, $ingresada]));
        $devolucion = $this->devolucionesDe($vencida);
        $this->assertSame([['12600.00', self::BILLETERA, 'confirmado']], array_map(fn ($d) => [$d['monto_pesos'], $d['billetera'], $d['estado']], $devolucion));
        $this->assertSame([], $this->devolucionesDe($aTiempo));

        //correrlo de nuevo no cancela ni devuelve dos veces
        $this->assertSame([0, "No hay reservas con el saldo vencido.\n"], $this->correrScript('cancelar-saldos-vencidos.php'));
        $this->assertCount(1, $this->devolucionesDe($vencida));
    }

    // ---------- sin devolución ----------

    //Con una política que retiene el 100 % de la seña en todos los tramos no hay nada que devolver: se cancela sin
    //devolución y no se manda nada al contrato
    public function testSinNadaQueDevolverNoHayDevolucion(): void
    {
        $this->pdo()->prepare('UPDATE unidades SET retencion_mas_de_30 = 100, retencion_de_15_a_30 = 100, retencion_menos_de_15 = 100 WHERE id = ?')
            ->execute([$this->unidadId]);
        $cliente = $this->huesped();
        $reservaId = $this->reservaConfirmada($cliente, 50);

        $respuesta = $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Cancelaste la reserva: esas noches quedaron libres. Según la política de cancelación, no corresponde devolución', $respuesta->json()['ok']);
        $devolucion = $respuesta->json()['devolucion'];
        $this->assertSame(['0.00', null, null, null, null], [$devolucion['devolucion'], $devolucion['estado'], $devolucion['tx_hash'], $devolucion['red'], $devolucion['simulado']]);
        $this->assertSame([], $this->devolucionesDe($reservaId));
        $this->assertSame('cancelada', $this->estadoDe($reservaId));
    }

    //Una pendiente se cancela como antes: no pagó nada; la vista previa lo dice
    public function testLaPendienteSeCancelaSinDevolucion(): void
    {
        $cliente = $this->huesped();
        $reservaId = $this->reservar($cliente, 50);

        $vista = $cliente->get('/reservas/cancelacion', ['reserva_id' => $reservaId])->json();
        $this->assertSame(['pendiente', '0.00'], [$vista['estado'], $vista['devolucion']]);
        $this->assertSame(['ok' => 'Cancelaste la reserva: esas noches quedaron libres'], $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId])->json());
    }

    // ---------- cuándo no se cancela ----------

    //Una estadía que ya empezó o terminó no se cancela desde la app; la de otro huésped "no existe"
    public function testCuandoNoSeCancela(): void
    {
        $cliente = $this->huesped();
        $empezada = $this->reservaConfirmada($cliente, 50);
        $this->pdo()->prepare('UPDATE reservas SET fecha_ingreso = ?, fecha_egreso = ? WHERE id = ?')->execute([$this->fecha(), $this->fecha('+3 days'), $empezada]);
        $terminada = $this->reservaConfirmada($cliente, 60);
        $this->pdo()->prepare("UPDATE reservas SET estado = 'finalizada' WHERE id = ?")->execute([$terminada]);

        foreach ([$empezada => 'La estadía ya empezó: no se puede cancelar desde la app', $terminada => 'Esa estadía ya terminó'] as $reservaId => $error) {
            foreach ([$cliente->get('/reservas/cancelacion', ['reserva_id' => $reservaId]), $cliente->post('/reservas/cancelar', ['reserva_id' => $reservaId])] as $respuesta) {
                $this->assertSame([409, ['error' => $error]], [$respuesta->codigo, $respuesta->json()]);
            }
            $this->assertSame([], $this->devolucionesDe($reservaId));
        }
        $this->assertFalse($cliente->get('/reservas/mias')->json()['reservas'][1]['puede_cancelar']);//la empezada

        $otro = $this->huesped();
        $this->assertSame(404, $otro->get('/reservas/cancelacion', ['reserva_id' => $terminada])->codigo);
        $this->assertSame(404, $otro->get('/reservas/cancelacion', ['reserva_id' => 'abc'])->codigo);
        $this->assertSame(401, $this->cliente()->get('/reservas/cancelacion', ['reserva_id' => $terminada])->codigo);
    }
}
