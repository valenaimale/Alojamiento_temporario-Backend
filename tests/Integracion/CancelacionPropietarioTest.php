<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//La cancelación del propietario y la excepción a la retención (L2-19, decisiones #62 a #66):
//  - el propietario cancela con un motivo y se le devuelve al huésped TODO lo que pagó;
//  - cuando cancela el huésped, el propietario tiene 48 hs para devolverle lo que quiera de SU parte de lo retenido;
//    sale con la liquidación de la cancelada, en la misma transacción liquidar().
//Usa el verificador y el firmador simulados, como CancelacionTest.
final class CancelacionPropietarioTest extends CasoDeIntegracion
{
    private const BILLETERA = '0x000000000000000000000000000000000000beef';//la de prueba del verificador simulado

    private int $unidadId;
    private array $propietario;

    //Antes de cada test: una unidad publicada, sin administrador, con $70.000 por noche, 20 % de seña y retenciones de
    //30 %, 50 % y 70 %
    protected function setUp(): void
    {
        $this->propietario = $this->crearPropietarioEnBase();
        $this->unidadId = $this->crearUnidadEnBase($this->propietario['id'], [
            'nombre' => 'Cabaña del Mar', 'estado_publicacion' => 'publicada', 'capacidad' => 4,
        ]);
        $this->agregarFotoEnBase($this->unidadId);
        $this->cargarPreciosEnBase($this->unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $this->propietario['id']);
    }

    // ---------- cancela el propietario ----------

    //Con la seña y el saldo pagados, a 10 días del ingreso: se le devuelve TODO al huésped, sin importar el tramo
    public function testElPropietarioCancelaYSeLeDevuelveTodoAlHuesped(): void
    {
        $huesped = $this->huesped();
        $reservaId = $this->reservaPagada($huesped, 10, true);

        $respuesta = $this->dueno()->post('/unidades/reservas/cancelar', ['reserva_id' => $reservaId, 'motivo' => 'Se rompió el termotanque']);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('210000.00', $respuesta->json()['devolucion']['devolucion']);
        $this->assertSame('confirmado', $respuesta->json()['devolucion']['estado']);
        $this->assertSame([['210000.00', '2100000000000000', self::BILLETERA]], $this->pagosDe($reservaId, 'devolucion'));
        $cancelacion = $huesped->get('/reservas/mias')->json()['reservas'][0]['cancelacion'];
        $this->assertSame(['por' => 'propietario', 'motivo' => 'Se rompió el termotanque', 'excepcion' => null], $cancelacion);
        //no se retuvo nada: no hay nada que liquidar
        $this->generar();
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM liquidaciones WHERE reserva_id = $reservaId")->fetchColumn());
    }

    //Una pendiente se cancela sin devolución (no se pagó nada) y las noches quedan libres
    public function testElPropietarioCancelaUnaPendiente(): void
    {
        $reservaId = $this->reservar($this->huesped(), 20);

        $respuesta = $this->dueno()->post('/unidades/reservas/cancelar', ['reserva_id' => $reservaId, 'motivo' => 'Vendí la casa']);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['cancelada', 'propietario'], array_values($this->pdo()->query(
            "SELECT estado, cancelada_por FROM reservas WHERE id = $reservaId"
        )->fetch(\PDO::FETCH_NUM)));
        $this->assertSame([], $this->pagosDe($reservaId, 'devolucion'));
    }

    //El motivo es obligatorio: el huésped tiene que saber por qué
    public function testSinMotivoNoSeCancela(): void
    {
        $reservaId = $this->reservaPagada($this->huesped(), 20);

        foreach ([null, '', '  ab ', str_repeat('x', 501)] as $motivo) {
            $respuesta = $this->dueno()->post('/unidades/reservas/cancelar', ['reserva_id' => $reservaId, 'motivo' => $motivo]);
            $this->assertSame(422, $respuesta->codigo, $respuesta->cuerpo);
        }
        $this->assertSame('confirmada', $this->pdo()->query("SELECT estado FROM reservas WHERE id = $reservaId")->fetchColumn());
    }

    //Otro propietario no la encuentra (404) y el administrador de la unidad no puede cancelar (#66)
    public function testSoloElPropietarioDeLaUnidadCancela(): void
    {
        $reservaId = $this->reservaPagada($this->huesped(), 20);
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($this->unidadId, $administrador['id']);
        $cuerpo = ['reserva_id' => $reservaId, 'motivo' => 'Porque sí'];

        $this->assertSame(404, $this->iniciarSesion($this->crearPropietarioEnBase()['mail'])->post('/unidades/reservas/cancelar', $cuerpo)->codigo);
        $this->assertSame(403, $this->iniciarSesion($administrador['mail'])->post('/unidades/reservas/cancelar', $cuerpo)->codigo);
        $this->assertSame(401, $this->cliente()->post('/unidades/reservas/cancelar', $cuerpo)->codigo);
    }

    //Una estadía que ya empezó no se cancela, ni una ya cancelada
    public function testCuandoElPropietarioNoPuedeCancelar(): void
    {
        $reservaId = $this->reservaPagada($this->huesped(), 20);
        $this->assertSame(200, $this->dueno()->post('/unidades/reservas/cancelar', ['reserva_id' => $reservaId, 'motivo' => 'Inundación'])->codigo);

        $respuesta = $this->dueno()->post('/unidades/reservas/cancelar', ['reserva_id' => $reservaId, 'motivo' => 'Inundación']);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Esa reserva ya estaba cancelada', $respuesta->json()['error']);
    }

    // ---------- la excepción (48 hs) ----------

    //El huésped cancela a 45 días del cobro: se retienen $12.600 (30 % de la seña). El propietario puede devolverle hasta
    //su parte ($11.655: la plataforma cobra su 7,5 % igual), una sola vez, y sale con la liquidación de la cancelada
    public function testElPropietarioLeDaUnaExcepcionAlHuesped(): void
    {
        $huesped = $this->huesped();
        $reservaId = $this->reservaPagada($huesped, 50);
        $this->assertSame(200, $huesped->post('/reservas/cancelar', ['reserva_id' => $reservaId])->codigo);

        //mientras corren las 48 hs, no se liquida
        $this->generar();
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM liquidaciones WHERE reserva_id = $reservaId")->fetchColumn());

        $vista = $this->dueno()->get('/unidades/reservas/excepcion', ['reserva_id' => $reservaId])->json();
        $this->assertSame(['12600.00', '11655.00', true, null], [$vista['retenido'], $vista['maximo'], $vista['puede'], $vista['motivo']]);
        $canceladas = $this->dueno()->get('/unidades/reservas', ['id' => $this->unidadId])->json()['canceladas'];
        $this->assertSame([$reservaId], array_column($canceladas, 'id'));
        $this->assertTrue($canceladas[0]['excepcion']['puede']);

        $deMas = $this->dueno()->post('/unidades/reservas/excepcion', ['reserva_id' => $reservaId, 'monto' => '11655.01']);
        $this->assertSame(422, $deMas->codigo, $deMas->cuerpo);

        $respuesta = $this->dueno()->post('/unidades/reservas/excepcion', ['reserva_id' => $reservaId, 'monto' => '5000']);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(409, $this->dueno()->post('/unidades/reservas/excepcion', ['reserva_id' => $reservaId, 'monto' => '100'])->codigo);
        $this->assertSame('5000.00', $huesped->get('/reservas/mias')->json()['reservas'][0]['cancelacion']['excepcion']);

        //dada la excepción ya no se espera: se liquida enseguida, y la excepción sale de la parte del propietario
        $liquidacion = $this->dueno()->get('/liquidaciones/mias')->json()['liquidaciones'][0];
        $this->assertSame(['cancelacion', '12600.00', '945.00', '5000.00', '6655.00'], [
            $liquidacion['motivo'], $liquidacion['importe_cobrado'], $liquidacion['comision_plataforma'],
            $liquidacion['excepcion'], $liquidacion['importe_propietario'],
        ]);

        //al pagarla, el huésped recibe la excepción en la misma transacción liquidar()
        $pago = $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $liquidacion['id']]);
        $this->assertSame(200, $pago->codigo, $pago->cuerpo);
        $this->assertSame([['5000.00', '50000000000000', self::BILLETERA]], $this->pagosDe($reservaId, 'excepcion'));
        $total = $this->pdo()->query(
            "SELECT SUM(monto_wei) FROM pagos WHERE reserva_id = $reservaId AND tipo IN ('liquidacion', 'excepcion')"
        )->fetchColumn();
        $this->assertSame('126000000000000', (string) $total);//exactamente lo retenido: 42.000 − 29.400 pesos, en wei
    }

    //Sin excepción, la cancelada se liquida recién cuando pasan las 48 hs; después ya no se puede dar
    public function testSinExcepcionSeLiquidaPasadasLas48Horas(): void
    {
        $huesped = $this->huesped();
        $reservaId = $this->reservaPagada($huesped, 50);
        $huesped->post('/reservas/cancelar', ['reserva_id' => $reservaId]);
        $this->pdo()->exec("UPDATE reservas SET cancelada_en = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = $reservaId");

        $vista = $this->dueno()->get('/unidades/reservas/excepcion', ['reserva_id' => $reservaId])->json();
        $this->assertFalse($vista['puede']);
        $this->assertSame('Pasaron las 48 hs para dar una excepción', $vista['motivo']);
        $this->assertSame(409, $this->dueno()->post('/unidades/reservas/excepcion', ['reserva_id' => $reservaId, 'monto' => '100'])->codigo);

        $this->generar();
        $this->assertSame('11655.00', $this->pdo()->query("SELECT importe_propietario FROM liquidaciones WHERE reserva_id = $reservaId")->fetchColumn());
    }

    //Si el propietario decide no devolver nada, la cancelada se liquida enseguida (sin esperar las 48 hs), sin parte
    //para el huésped, y después ya no puede dar una excepción ni volver a decidir
    public function testElPropietarioDecideNoDevolverNada(): void
    {
        $huesped = $this->huesped();
        $reservaId = $this->reservaPagada($huesped, 50);
        $huesped->post('/reservas/cancelar', ['reserva_id' => $reservaId]);

        $respuesta = $this->dueno()->post('/unidades/reservas/excepcion/renunciar', ['reserva_id' => $reservaId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $vista = $this->dueno()->get('/unidades/reservas/excepcion', ['reserva_id' => $reservaId])->json();
        $this->assertSame([false, true, null, 'Ya decidiste no devolverle nada al huésped'],
            [$vista['puede'], $vista['renuncio'], $vista['excepcion'], $vista['motivo']]);
        $this->assertSame(409, $this->dueno()->post('/unidades/reservas/excepcion', ['reserva_id' => $reservaId, 'monto' => '100'])->codigo);
        $this->assertSame(409, $this->dueno()->post('/unidades/reservas/excepcion/renunciar', ['reserva_id' => $reservaId])->codigo);

        //ya liquidada, con toda su parte para el propietario
        $liquidacion = $this->dueno()->get('/liquidaciones/mias')->json()['liquidaciones'][0];
        $this->assertSame(['cancelacion', '12600.00', '0.00', '11655.00'], [
            $liquidacion['motivo'], $liquidacion['importe_cobrado'], $liquidacion['excepcion'], $liquidacion['importe_propietario'],
        ]);
    }

    //Solo decide el propietario de la unidad, y solo cuando canceló el huésped
    public function testNoDevolverNadaSoloLoDecideElPropietarioCuandoCancelaElHuesped(): void
    {
        $reservaId = $this->reservaPagada($this->huesped(), 50);
        $this->assertSame(404, $this->iniciarSesion($this->crearPropietarioEnBase()['mail'])
            ->post('/unidades/reservas/excepcion/renunciar', ['reserva_id' => $reservaId])->codigo);

        $this->dueno()->post('/unidades/reservas/cancelar', ['reserva_id' => $reservaId, 'motivo' => 'Se me rompió el techo']);
        $respuesta = $this->dueno()->post('/unidades/reservas/excepcion/renunciar', ['reserva_id' => $reservaId]);
        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Solo se puede dar una excepción cuando cancela el huésped', $respuesta->json()['error']);
    }

    //Con el saldo pagado se retiene todo (#62), y la excepción puede devolver hasta la parte del propietario
    public function testConElSaldoPagadoLaExcepcionLlegaHastaLaParteDelPropietario(): void
    {
        $huesped = $this->huesped();
        $reservaId = $this->reservaPagada($huesped, 10, true);
        $this->assertSame('0.00', $huesped->post('/reservas/cancelar', ['reserva_id' => $reservaId])->json()['devolucion']['devolucion']);

        $vista = $this->dueno()->get('/unidades/reservas/excepcion', ['reserva_id' => $reservaId])->json();

        $this->assertSame(['210000.00', '194250.00', true], [$vista['retenido'], $vista['maximo'], $vista['puede']]);
    }

    //Si canceló el propietario no hay excepción que dar (ya se devolvió todo), y otro propietario no la encuentra
    public function testLaExcepcionEsSoloCuandoCancelaElHuesped(): void
    {
        $reservaId = $this->reservaPagada($this->huesped(), 50);
        $this->dueno()->post('/unidades/reservas/cancelar', ['reserva_id' => $reservaId, 'motivo' => 'Se me rompió el techo']);

        $respuesta = $this->dueno()->post('/unidades/reservas/excepcion', ['reserva_id' => $reservaId, 'monto' => '100']);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Solo se puede dar una excepción cuando cancela el huésped', $respuesta->json()['error']);
        $this->assertSame(404, $this->iniciarSesion($this->crearPropietarioEnBase()['mail'])
            ->get('/unidades/reservas/excepcion', ['reserva_id' => $reservaId])->codigo);
    }

    // ---------- Ayudas de este test ----------

    //Un huésped con el mail verificado y su sesión iniciada
    private function huesped(): ClienteApi
    {
        $huesped = $this->crearUsuarioEnBase('huesped');
        $this->pdo()->prepare('UPDATE usuarios SET mail_verificado_en = UTC_TIMESTAMP() WHERE id = ?')->execute([$huesped['id']]);
        return $this->iniciarSesion($huesped['mail']);
    }

    //El propietario de la unidad con su sesión iniciada
    private function dueno(): ClienteApi
    {
        return $this->iniciarSesion($this->propietario['mail']);
    }

    //Reserva 3 noches ($210.000, seña $42.000, saldo $168.000) desde $dias días adelante. Devuelve el id.
    private function reservar(ClienteApi $cliente, int $dias): int
    {
        $respuesta = $cliente->post('/reservas/crear', [
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha("+$dias days"), 'egreso' => $this->fecha('+' . ($dias + 3) . ' days'),
            'personas' => 2, 'acepta_politica' => true,
        ]);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['reserva']['id'];
    }

    //Reserva y paga la seña (y el saldo, si $saldo) con el verificador simulado. Devuelve el id.
    private function reservaPagada(ClienteApi $cliente, int $dias, bool $saldo = false): int
    {
        $reservaId = $this->reservar($cliente, $dias);
        foreach ($saldo ? ['sena', 'saldo'] : ['sena'] as $tipo) {
            $hash = '0x' . bin2hex(random_bytes(30)) . '0001';//el verificador simulado confirma los que terminan así
            $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $hash, 'tipo' => $tipo]);
            $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        }
        return $reservaId;
    }

    //Los pagos de ese tipo de la reserva: [pesos, wei, billetera]
    private function pagosDe(int $reservaId, string $tipo): array
    {
        $consulta = $this->pdo()->prepare('SELECT monto_pesos, monto_wei, billetera FROM pagos WHERE reserva_id = ? AND tipo = ? ORDER BY id');
        $consulta->execute([$reservaId, $tipo]);
        return $consulta->fetchAll(\PDO::FETCH_NUM);
    }

    //Un backoffice nuevo con la sesión iniciada
    private function sesionDeBackoffice(): ClienteApi
    {
        return $this->iniciarSesion($this->crearUsuarioEnBase('backoffice')['mail']);
    }

    //Genera las liquidaciones pendientes como lo hace la plataforma
    private function generar(): void
    {
        $respuesta = $this->sesionDeBackoffice()->post('/liquidaciones/generar');
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
    }
}
