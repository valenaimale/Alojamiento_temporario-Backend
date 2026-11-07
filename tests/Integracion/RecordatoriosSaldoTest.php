<?php

namespace Tests\Integracion;

use Tests\Soporte\EntornoDePrueba;

//Los recordatorios del saldo (RF-25): a quién se le avisa, cada cuánto y hasta cuándo.
//Corre scripts/enviar-recordatorios.php de verdad, como lo correría el cron de Railway, contra la base
//de prueba, y después mira qué quedó anotado en recordatorios_saldo.
//Los mails en sí no se revisan acá: RecordatorioSaldo nunca falla y el envío ya se prueba en otros tests.
final class RecordatoriosSaldoTest extends CasoDeIntegracion
{
    /** @var int[] las reservas que armó este test, para borrarlas al terminar */
    private array $reservas = [];

    //Cada test se lleva sus reservas al terminar. Si no, el script de la prueba siguiente también les
    //escribiría a las de la anterior: serían más mails y cada corrida tardaría más.
    protected function tearDown(): void
    {
        foreach ($this->reservas as $reserva) {
            foreach (['recordatorios_saldo', 'pagos', 'estadias'] as $tabla) {
                $this->pdo()->prepare("DELETE FROM $tabla WHERE reserva_id = ?")->execute([$reserva]);
            }
            $this->pdo()->prepare('DELETE FROM reservas WHERE id = ?')->execute([$reserva]);
        }
        $this->reservas = [];
    }

    //Una reserva confirmada con el saldo sin pagar y la fecha de cobro ya cumplida recibe su recordatorio
    public function testLeRecuerdaElSaldoAQuienNoLoPago(): void
    {
        $reserva = $this->reservaConSaldoImpago();

        $salida = $this->correrScript(true);

        $this->assertStringContainsString("reserva $reserva", $salida);
        $this->assertSame([1], $this->recordatoriosDe($reserva));
    }

    //Sin --enviar no manda ni anota nada: solo dice a quién le escribiría
    public function testSinEnviarNoAnotaNada(): void
    {
        $reserva = $this->reservaConSaldoImpago();

        $salida = $this->correrScript(false);

        $this->assertStringContainsString('[simulacion]', $salida);
        $this->assertStringContainsString("reserva $reserva", $salida);
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Correrlo dos veces seguidas no le manda dos mails: entre uno y otro tienen que pasar
    //horas_entre_recordatorios (24 por defecto)
    public function testNoLoAtosigaSiSeCorreDosVecesElMismoDia(): void
    {
        $reserva = $this->reservaConSaldoImpago();

        $this->correrScript(true);
        $this->correrScript(true);

        $this->assertSame([1], $this->recordatoriosDe($reserva));
    }

    //Pasadas las horas, le toca el segundo
    public function testAlDiaSiguienteLeTocaElSegundo(): void
    {
        $reserva = $this->reservaConSaldoImpago();
        $this->correrScript(true);
        $this->envejecerRecordatorios($reserva, '2 days');

        $this->correrScript(true);

        $this->assertSame([1, 2], $this->recordatoriosDe($reserva));
    }

    //Después del último (max_recordatorios_cobro, 3 por defecto) no se le manda nada más:
    //de ahí en adelante es una deuda, no un olvido
    public function testDespuesDelTerceroNoSeLeMandaMas(): void
    {
        $reserva = $this->reservaConSaldoImpago();
        for ($vuelta = 1; $vuelta <= 3; $vuelta++) {
            $this->correrScript(true);
            $this->envejecerRecordatorios($reserva, '2 days');
        }

        $this->correrScript(true);

        $this->assertSame([1, 2, 3], $this->recordatoriosDe($reserva));
    }

    //Si ya pagó todo, no hay nada que recordarle
    public function testNoLeRecuerdaNadaAQuienYaPago(): void
    {
        $reserva = $this->reservaConSaldoImpago();
        $this->pagarEnBase($reserva, 'saldo', '320000.00');

        $this->correrScript(true);

        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Si todavía falta mucho para el ingreso, el saldo no se pide: no es momento
    public function testNoPideElSaldoAntesDeTiempo(): void
    {
        $reserva = $this->reservaConSaldoImpago(['ingreso' => '+40 days', 'egreso' => '+45 days']);

        $this->correrScript(true);

        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Si el huésped ya ingresó, el saldo se resuelve en el mostrador y no por mail
    public function testNoLeEscribeAQuienYaIngreso(): void
    {
        $reserva = $this->reservaConSaldoImpago();
        $operador = $this->crearUsuarioEnBase('operador');
        $this->pdo()->prepare(
            'INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id)
             VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)'
        )->execute([$reserva, $operador['id'], $this->uuid()]);

        $this->correrScript(true);

        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Una reserva pendiente (ni siquiera pagó la seña) no entra: lo que vence ahí es el bloqueo, no el saldo
    public function testUnaReservaPendienteNoRecibeRecordatorioDeSaldo(): void
    {
        $reserva = $this->reservaConSaldoImpago(['estado' => 'pendiente', 'pagar_sena' => false]);

        $this->correrScript(true);

        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Una reserva cancelada no recibe recordatorios: ya no hay reserva que salvar pagando el saldo.
    //Es el estado en el que la deja la cancelación automática del saldo vencido (#23).
    public function testUnaReservaCanceladaNoRecibeRecordatorios(): void
    {
        $reserva = $this->reservaConSaldoImpago(['estado' => 'cancelada']);

        $this->correrScript(true);

        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Pasado el plazo para pagar el saldo (la fecha de cobro más dias_vencimiento_saldo, 3 por defecto)
    //no se le manda otro mail: lo que corresponde es cancelarla, no volver a pedirle la plata (#23)
    public function testNoLeEscribeSiYaSeLePasoElPlazo(): void
    {
        $reserva = $this->reservaConSaldoImpago([
            'ingreso' => '+10 days', 'egreso' => '+15 days', 'fecha_cobro' => '-4 days',
        ]);

        $this->correrScript(true);

        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //El último día del plazo todavía recibe su recordatorio: ese día puede pagarlo, incluso al ingresar (#23 y #25)
    public function testElUltimoDiaDelPlazoTodaviaRecibeRecordatorio(): void
    {
        $reserva = $this->reservaConSaldoImpago([
            'ingreso' => '+10 days', 'egreso' => '+15 days', 'fecha_cobro' => '-3 days',
        ]);

        $this->correrScript(true);

        $this->assertSame([1], $this->recordatoriosDe($reserva));
    }

    //El recordatorio dice hasta qué día se puede pagar: es el plazo que después usa la cancelación automática.
    //Con el ingreso en 3 días y la fecha de cobro hace 2, el plazo se corta en el ingreso y no a los 3 días.
    public function testElRecordatorioAnunciaElPlazoParaPagar(): void
    {
        $reserva = $this->reservaConSaldoImpago();
        $limite = $this->fecha('+1 day');   //la fecha de cobro (hace 2 días) más los 3 días del plazo

        $salida = $this->correrScript(true);

        $this->assertStringContainsString("reserva $reserva", $salida);
        $this->assertStringContainsString("paga hasta el $limite", $salida);
    }

    // ---------- Ayudas de este test ----------

    //Una reserva confirmada de 5 noches que empiezan en 3 días, con la seña pagada y el saldo sin pagar.
    //Como la hace el Rol 2 al reservar: con monto_faltante (el saldo) y fecha_cobro, que es de donde salen
    //el importe que se reclama y el plazo que anuncia el mail.
    //Opciones: ingreso, egreso, estado, pagar_sena y fecha_cobro (por defecto, 5 días antes del ingreso,
    //que es dias_cobro_saldo).
    //Devuelve el id de la reserva.
    private function reservaConSaldoImpago(array $opciones = []): int
    {
        $opciones = array_merge([
            'ingreso' => '+3 days', 'egreso' => '+8 days', 'estado' => 'confirmada', 'pagar_sena' => true,
            'fecha_cobro' => null,
        ], $opciones);

        $propietario = $this->crearPropietarioEnBase();
        $huesped = $this->crearUsuarioEnBase('huesped');
        $unidad = $this->crearUnidadEnBase($propietario['id']);

        $ingreso = $this->fecha($opciones['ingreso']);
        $fechaCobro = $opciones['fecha_cobro'] === null
            ? (new \DateTimeImmutable($ingreso))->modify('-5 days')->format('Y-m-d')
            : $this->fecha($opciones['fecha_cobro']);

        $this->pdo()->prepare(
            "INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado,
                                   importe_total, porcentaje_sena, importe_sena, monto_faltante, fecha_cobro,
                                   retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15,
                                   politica_aceptada_en, creado_en)
             VALUES (?, ?, ?, ?, 2, ?, '400000.00', 20, '80000.00', '320000.00', ?, 30, 50, 70,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP() - INTERVAL 20 DAY)"
        )->execute([
            $unidad, $huesped['id'], $ingreso, $this->fecha($opciones['egreso']), $opciones['estado'], $fechaCobro,
        ]);
        $reserva = (int) $this->pdo()->lastInsertId();
        $this->reservas[] = $reserva;

        if ($opciones['pagar_sena']) {
            $this->pagarEnBase($reserva, 'sena', '80000.00');
        }
        return $reserva;
    }

    //Un pago ya confirmado de la reserva, con el formato que exigen los CHECK de la tabla.
    //Pagar el saldo deja monto_faltante en 0, como lo hace PagosController al confirmar el pago (#20).
    private function pagarEnBase(int $reservaId, string $tipo, string $pesos): void
    {
        $this->pdo()->prepare(
            "INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red,
                                tx_hash, estado, confirmado_en)
             VALUES (?, ?, ?, ?, '100000000', ?, 31337, ?, 'confirmado', UTC_TIMESTAMP())"
        )->execute([
            $reservaId, $tipo, $pesos,
            (string) (int) ((float) $pesos * 10_000_000_000),
            '0x' . bin2hex(random_bytes(20)),
            '0x' . bin2hex(random_bytes(32)),
        ]);

        if ($tipo === 'saldo') {
            $this->pdo()->prepare('UPDATE reservas SET monto_faltante = 0 WHERE id = ?')->execute([$reservaId]);
        }
    }

    //Mueve para atrás los recordatorios de la reserva, para simular que pasaron los días
    private function envejecerRecordatorios(int $reservaId, string $cuanto): void
    {
        $atras = (new \DateTimeImmutable("now -$cuanto", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->pdo()->prepare('UPDATE recordatorios_saldo SET enviado_en = ? WHERE reserva_id = ?')
            ->execute([$atras, $reservaId]);
    }

    //Los números de los recordatorios que se le mandaron a esa reserva, en orden
    private function recordatoriosDe(int $reservaId): array
    {
        $consulta = $this->pdo()->prepare('SELECT numero FROM recordatorios_saldo WHERE reserva_id = ? ORDER BY numero');
        $consulta->execute([$reservaId]);
        return array_map('intval', $consulta->fetchAll(\PDO::FETCH_COLUMN));
    }

    //Corre scripts/enviar-recordatorios.php contra la base de prueba y devuelve lo que imprimió
    private function correrScript(bool $enviar): string
    {
        $db = EntornoDePrueba::datosBase();
        $entorno = array_merge(getenv(), [
            'DB_HOST'         => $db['host'],
            'DB_PORT'         => $db['port'],
            'DB_USER'         => $db['user'],
            'DB_PASS'         => $db['pass'],
            'DB_NAME'         => $db['name'],
            'MAIL_TRANSPORTE' => 'smtp',   //a Mailpit, nunca a un proveedor real
        ]);
        $argumentos = [PHP_BINARY, RAIZ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'enviar-recordatorios.php'];
        if ($enviar) {
            $argumentos[] = '--enviar';
        }

        //La salida de error va a un archivo y no a una tubería: el script escribe ahí los mails que no
        //pudieron salir (el log), y si nadie la vacía mientras se lee la salida normal, el proceso se traba.
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'alojamiento-tests-recordatorios.log';
        $proceso = proc_open(
            $argumentos,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']],
            $tuberias,
            RAIZ,
            $entorno
        );
        $this->assertIsResource($proceso, 'No se pudo correr scripts/enviar-recordatorios.php');

        fclose($tuberias[0]);
        $salida = stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);
        $codigo = proc_close($proceso);

        $this->assertSame(0, $codigo, "El script termino con error: $salida (ver $log)");
        return $salida;
    }

    //Un identificador de movimiento con la forma de un UUID (el formato que exige la tabla estadias)
    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }
}
