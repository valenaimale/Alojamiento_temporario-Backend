<?php

namespace Tests\Integracion;

use Tests\Soporte\EntornoDePrueba;

//Los recordatorios del saldo (RF-25 y decisión #75): a quién se le avisa y qué días (3 días antes del último día
//para pagar, el día anterior y el mismo día).
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

    //A 3 días del último día para pagar (la fecha de cobro) le llega el primer recordatorio (#75)
    public function test_TresDiasAntesLeLlegaElPrimerRecordatorio(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days']);

        // Act
        $salida = $this->correrScript(true);

        // Assert
        $this->assertStringContainsString("reserva $reserva", $salida);
        $this->assertSame([1], $this->recordatoriosDe($reserva));
    }

    //Sin --enviar no manda ni anota nada: solo dice a quién le escribiría
    public function test_SinEnviarNoAnotaNada(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days']);

        // Act
        $salida = $this->correrScript(false);

        // Assert
        $this->assertStringContainsString('[simulacion]', $salida);
        $this->assertStringContainsString("reserva $reserva", $salida);
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Correrlo dos veces el mismo día no le manda dos mails: cada recordatorio sale una sola vez
    public function test_CorrerloDosVecesElMismoDiaNoRepiteElMail(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days']);
        $this->correrScript(true);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([1], $this->recordatoriosDe($reserva));
    }

    //A 4 días todavía no se le escribe: el primero sale a los 3
    public function test_ConCuatroDiasTodaviaNoSeLeEscribe(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+4 days']);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //El día anterior le toca el segundo
    public function test_ElDiaAnteriorLeTocaElSegundo(): void
    {
        // Arrange: ya recibió el de los 3 días, y ahora falta 1
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days']);
        $this->correrScript(true);
        $this->moverFechaDeCobro($reserva, '+1 day');

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([1, 2], $this->recordatoriosDe($reserva));
    }

    //El mismo día le toca el tercero, el último; después de ese no se le manda nada más
    public function test_ElMismoDiaLeTocaElUltimo(): void
    {
        // Arrange: ya recibió los dos primeros, y hoy es el último día
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days']);
        $this->correrScript(true);
        $this->moverFechaDeCobro($reserva, '+1 day');
        $this->correrScript(true);
        $this->moverFechaDeCobro($reserva, 'today');

        // Act
        $this->correrScript(true);
        $this->correrScript(true);

        // Assert
        $this->assertSame([1, 2, 3], $this->recordatoriosDe($reserva));
    }

    //Si el cron no corrió el día del primero, no se le mandan los atrasados: el día anterior recibe solo el segundo
    public function test_NoSeMandanLosRecordatoriosAtrasados(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+1 day']);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([2], $this->recordatoriosDe($reserva));
    }

    //Si ya pagó todo, no hay nada que recordarle
    public function test_NoLeRecuerdaNadaAQuienYaPago(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days']);
        $this->pagarEnBase($reserva, 'saldo', '320000.00');

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Quien reservó con 5 días o menos pagó todo con la seña (#75): no le queda saldo ni recibe recordatorios
    public function test_QuienPagoElTotalAlReservarNoRecibeRecordatorios(): void
    {
        // Arrange: una reserva de pago total (porcentaje_sena 100, sin saldo)
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => 'today', 'pago_total' => true]);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Si el huésped ya ingresó, el saldo se resuelve en el mostrador y no por mail
    public function test_NoLeEscribeAQuienYaIngreso(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => 'today', 'ingreso' => 'today', 'egreso' => '+3 days']);
        $operador = $this->crearUsuarioEnBase('operador');
        $this->pdo()->prepare(
            'INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id)
             VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)'
        )->execute([$reserva, $operador['id'], $this->uuid()]);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Una reserva pendiente (ni siquiera pagó la seña) no entra: lo que vence ahí es el bloqueo, no el saldo
    public function test_UnaReservaPendienteNoRecibeRecordatorioDeSaldo(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days', 'estado' => 'pendiente', 'pagar_sena' => false]);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Una reserva cancelada no recibe recordatorios: es el estado en el que la deja la cancelación automática (#23)
    public function test_UnaReservaCanceladaNoRecibeRecordatorios(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days', 'estado' => 'cancelada']);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //Pasada la fecha de cobro no hay más días de gracia (#75): no se le escribe, lo que corresponde es cancelarla
    public function test_PasadaLaFechaDeCobroNoSeLeEscribe(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '-1 day']);

        // Act
        $this->correrScript(true);

        // Assert
        $this->assertSame([], $this->recordatoriosDe($reserva));
    }

    //El recordatorio dice hasta qué día se puede pagar: la fecha de cobro, el mismo plazo que usa la cancelación
    public function test_ElRecordatorioAnunciaLaFechaDeCobroComoPlazo(): void
    {
        // Arrange
        $reserva = $this->reservaConSaldoImpago(['fecha_cobro' => '+3 days']);

        // Act
        $salida = $this->correrScript(true);

        // Assert
        $this->assertStringContainsString("reserva $reserva", $salida);
        $this->assertStringContainsString('paga hasta el ' . $this->fecha('+3 days'), $salida);
    }

    // ---------- Ayudas de este test ----------

    //Una reserva confirmada de 5 noches que empiezan en 8 días, con la seña pagada y el saldo sin pagar.
    //Como la hace el Rol 2 al reservar: con monto_faltante (el saldo) y fecha_cobro, que es de donde salen
    //el importe que se reclama y el plazo que anuncia el mail (el último día para pagar es la fecha de cobro, #75).
    //Opciones: ingreso, egreso, estado, pagar_sena, fecha_cobro (por defecto, 5 días antes del ingreso,
    //que es dias_cobro_saldo) y pago_total (reservó con 5 días o menos: la seña es el total y no queda saldo).
    //Devuelve el id de la reserva.
    private function reservaConSaldoImpago(array $opciones = []): int
    {
        $opciones = array_merge([
            'ingreso' => '+8 days', 'egreso' => '+13 days', 'estado' => 'confirmada', 'pagar_sena' => true,
            'fecha_cobro' => null, 'pago_total' => false,
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
             VALUES (?, ?, ?, ?, 2, ?, '400000.00', ?, ?, ?, ?, 30, 50, 70,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP() - INTERVAL 20 DAY)"
        )->execute([
            $unidad, $huesped['id'], $ingreso, $this->fecha($opciones['egreso']), $opciones['estado'],
            ...($opciones['pago_total'] ? ['100', '400000.00', '0.00'] : ['20', '80000.00', '320000.00']),
            $fechaCobro,
        ]);
        $reserva = (int) $this->pdo()->lastInsertId();
        $this->reservas[] = $reserva;

        if ($opciones['pagar_sena']) {
            $this->pagarEnBase($reserva, 'sena', $opciones['pago_total'] ? '400000.00' : '80000.00');
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

    //Acerca la fecha de cobro, para simular que pasaron los días (los recordatorios se cuentan hacia esa fecha)
    private function moverFechaDeCobro(int $reservaId, string $fecha): void
    {
        $this->pdo()->prepare('UPDATE reservas SET fecha_cobro = ? WHERE id = ?')->execute([$this->fecha($fecha), $reservaId]);
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
