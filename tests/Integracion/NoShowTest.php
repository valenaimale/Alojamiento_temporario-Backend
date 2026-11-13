<?php

namespace Tests\Integracion;

use App\Validacion\PlazoIngreso;
use Tests\Soporte\ClienteApi;

//El no-show (decisión #67 de docs/pagos-decisiones.md): hay 24 hs desde el horario de check-in de la unidad para
//registrar el ingreso; si nadie lo hace, la reserva pasa SOLA a 'no_show' (scripts/marcar-no-shows.php).
//Desde ahí se trata como una cancelación del huésped con todo pagado: se retiene lo pagado, las noches se liberan,
//el propietario tiene 48 hs para dar una excepción y lo retenido se liquida.
//
//Cómo se controla el plazo sin tocar el reloj: la reserva se crea y se paga por la API (con fechas futuras, como
//haría un huésped) y después se le mueven las fechas y el horario de check-in de la unidad, para que el límite de
//las 24 hs caiga justo donde lo necesita cada caso (reservaConPlazo).
//Usa el verificador y el firmador simulados, como CancelacionPropietarioTest.
final class NoShowTest extends CasoDeIntegracion
{
    private const ZONA = 'America/Argentina/Buenos_Aires';

    private int $unidadId;
    private array $propietario;

    //Antes de cada test: una unidad publicada, sin administrador, con $70.000 por noche y 20 % de seña.
    //Tres noches dan $210.000 de total, $42.000 de seña y $168.000 de saldo.
    protected function setUp(): void
    {
        $this->propietario = $this->crearPropietarioEnBase();
        $this->unidadId = $this->crearUnidadEnBase($this->propietario['id'], [
            'nombre' => 'Cabaña del Faro', 'estado_publicacion' => 'publicada', 'capacidad' => 4,
        ]);
        $this->agregarFotoEnBase($this->unidadId);
        $this->cargarPreciosEnBase($this->unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $this->propietario['id']);
    }

    //A las 23:59 hs del plazo todavía no se marca: falta un minuto para que se cumplan las 24 hs
    public function testAlMinutoAntesDeLas24HorasNoSeMarca(): void
    {
        $reservaId = $this->reservaConPlazo($this->huesped(), '+1 minute');

        $this->marcarNoShows();

        $this->assertSame(['confirmada', null], $this->estadoDe($reservaId));
    }

    //A las 24 hs sí: la reserva pasa a 'no_show' con su no_show_en, y esas noches quedan libres
    public function testALas24HorasSeMarcaYSeLiberanLasNoches(): void
    {
        $huesped = $this->huesped();
        $reservaId = $this->reservaConPlazo($huesped, '-1 minute');
        //antes de marcarla, la reserva ocupa sus noches y el propietario la ve en la agenda de la unidad
        $this->assertContains($reservaId, array_column($this->reservasDeLaUnidad()['reservas'], 'id'));

        $salida = $this->marcarNoShows();

        $this->assertStringContainsString("[no-show]    reserva $reservaId ", $salida);
        [$estado, $noShowEn] = $this->estadoDe($reservaId);
        $this->assertSame('no_show', $estado);
        $this->assertNotNull($noShowEn, 'un no_show sin no_show_en no lo acepta el CHECK de la base');

        //las noches que quedaban se liberan: un 'no_show' no ocupa, y el propietario la ve entre las canceladas
        $deLaUnidad = $this->reservasDeLaUnidad();
        $this->assertNotContains($reservaId, array_column($deLaUnidad['reservas'], 'id'));
        $this->assertSame([$reservaId], array_column($deLaUnidad['canceladas'], 'id'));
        $this->assertSame('no_show', $deLaUnidad['canceladas'][0]['motivo']);

        //el huésped ve que no se presentó, y ya no puede cancelarla
        $mia = $this->reservaDelHuesped($huesped, $reservaId);
        $this->assertSame('no_show', $mia['estado']);
        $this->assertFalse($mia['puede_cancelar']);
        $this->assertSame(str_replace(' ', 'T', $noShowEn) . 'Z', $mia['no_show']['en']);
    }

    //Una reserva con el ingreso registrado nunca se marca, aunque se le haya pasado el plazo
    public function testUnaReservaConIngresoNuncaSeMarca(): void
    {
        $reservaId = $this->reservaConPlazo($this->huesped(), '-1 minute');
        $this->registrarIngresoEnBase($reservaId);

        $this->marcarNoShows();

        $this->assertSame(['confirmada', null], $this->estadoDe($reservaId));
    }

    //Correr el script dos veces no cambia nada: la segunda corrida no la vuelve a marcar ni le mueve el no_show_en
    public function testCorrerElScriptDosVecesNoCambiaNada(): void
    {
        $reservaId = $this->reservaConPlazo($this->huesped(), '-1 minute');

        $this->marcarNoShows();
        $primera = $this->estadoDe($reservaId);
        $salida = $this->marcarNoShows();

        $this->assertSame($primera, $this->estadoDe($reservaId));
        $this->assertStringNotContainsString("reserva $reservaId ", $salida);
        $this->assertSame(1, (int) $this->pdo()
            ->query("SELECT COUNT(*) FROM reservas WHERE id = $reservaId AND estado = 'no_show'")->fetchColumn());
    }

    //El propietario puede darle una excepción al huésped que no se presentó: las 48 hs corren desde no_show_en,
    //y dada la excepción se liquida enseguida, con lo devuelto saliendo de su parte (#64, #65 y #67)
    public function testLaExcepcionFuncionaEnUnNoShow(): void
    {
        $reservaId = $this->reservaConPlazo($this->huesped(), '-1 minute', true);//seña y saldo: se retienen $210.000
        $this->marcarNoShows();

        $vista = $this->dueno()->get('/unidades/reservas/excepcion', ['reserva_id' => $reservaId])->json();
        $this->assertSame([true, '210000.00', '194250.00'], [$vista['puede'], $vista['retenido'], $vista['maximo']]);
        //el plazo son 48 hs desde que el sistema marcó el no-show, no desde la cancelación (que no existió)
        $this->assertSame($this->mas48Horas($this->estadoDe($reservaId)[1]), $vista['hasta']);

        $respuesta = $this->dueno()->post('/unidades/reservas/excepcion', ['reserva_id' => $reservaId, 'monto' => '5000']);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $liquidacion = $this->dueno()->get('/liquidaciones/mias')->json()['liquidaciones'][0];
        $this->assertSame(['no_show', '210000.00', '5000.00', '189250.00', null], [
            $liquidacion['motivo'], $liquidacion['importe_cobrado'], $liquidacion['excepcion'],
            $liquidacion['importe_propietario'], $liquidacion['faltante'],
        ]);
    }

    //Sin excepción, lo retenido se liquida recién cuando pasan las 48 hs desde el no-show
    public function testLaLiquidacionSaleALas48Horas(): void
    {
        $reservaId = $this->reservaConPlazo($this->huesped(), '-1 minute', true);
        $this->marcarNoShows();

        //a las 47 hs todavía se espera la excepción del propietario: lo retenido sigue en el contrato
        $this->pdo()->exec("UPDATE reservas SET no_show_en = UTC_TIMESTAMP() - INTERVAL 47 HOUR WHERE id = $reservaId");
        $this->generar();
        $this->assertSame([], $this->dueno()->get('/liquidaciones/mias')->json()['liquidaciones']);

        //a las 49 hs ya no, y se liquida todo lo retenido, sin deuda y sin parte para el huésped
        $this->pdo()->exec("UPDATE reservas SET no_show_en = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = $reservaId");
        $this->generar();

        $liquidacion = $this->dueno()->get('/liquidaciones/mias')->json()['liquidaciones'][0];
        $this->assertSame(['no_show', 'pendiente', '210000.00', '0.00', '194250.00', null], [
            $liquidacion['motivo'], $liquidacion['estado'], $liquidacion['importe_cobrado'],
            $liquidacion['excepcion'], $liquidacion['importe_propietario'], $liquidacion['faltante'],
        ]);
        //pasadas las 48 hs ya no puede dar una excepción
        $this->assertFalse($this->dueno()->get('/unidades/reservas/excepcion', ['reserva_id' => $reservaId])->json()['puede']);
    }

    //Con solo la seña pagada se retiene la seña, y es todo lo que corresponde: la liquidación no queda en deuda (RN-16)
    public function testConSoloLaSenaPagadaSeRetieneLaSena(): void
    {
        $reservaId = $this->reservaConPlazo($this->huesped(), '-1 minute');
        $this->marcarNoShows();
        $this->pdo()->exec("UPDATE reservas SET no_show_en = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = $reservaId");

        $this->generar();

        $liquidacion = $this->dueno()->get('/liquidaciones/mias')->json()['liquidaciones'][0];
        $this->assertSame(['no_show', 'pendiente', '42000.00', '38850.00', null], [
            $liquidacion['motivo'], $liquidacion['estado'], $liquidacion['importe_cobrado'],
            $liquidacion['importe_propietario'], $liquidacion['faltante'],
        ]);
    }

    //El comprobante de un no-show dice que se liquida por un no-show, y que no es una extensión
    public function testElComprobanteDiceQueEsUnNoShow(): void
    {
        $reservaId = $this->reservaConPlazo($this->huesped(), '-1 minute', true);
        $this->marcarNoShows();
        $this->pdo()->exec("UPDATE reservas SET no_show_en = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = $reservaId");
        $this->generar();
        $id = $this->dueno()->get('/liquidaciones/mias')->json()['liquidaciones'][0]['id'];

        $comprobante = $this->dueno()->get('/liquidaciones/comprobante', ['id' => $id])->json()['comprobante'];

        $this->assertSame('no_show', $comprobante['motivo']);
        $this->assertSame($reservaId, $comprobante['reserva']['id']);
        $this->assertNull($comprobante['reserva']['extiende_reserva_id']);
        $this->assertSame(3, $comprobante['reserva']['noches']);
        $this->assertTrue($comprobante['sin_valor_fiscal']);
    }

    // ---------- Ayudas de este test ----------

    //Una reserva confirmada y paga cuyo plazo para registrar el ingreso vence en $cuando respecto de ahora
    //('+1 minute' = falta un minuto; '-1 minute' = se pasó hace un minuto). Devuelve su id.
    //Se reserva y se paga con fechas futuras, por la API, y después se mueven las fechas y el horario de check-in:
    //así el límite de PlazoIngreso (check-in + 24 hs, en hora de Argentina) cae donde lo necesita el test.
    private function reservaConPlazo(ClienteApi $huesped, string $cuando, bool $saldo = false): int
    {
        $reservaId = $this->reservaPagada($huesped, 10, $saldo);

        $checkIn = (new \DateTimeImmutable('now', new \DateTimeZone(self::ZONA)))
            ->modify('-' . PlazoIngreso::HORAS . ' hours')
            ->modify($cuando);
        $this->pdo()->prepare('UPDATE unidades SET hora_check_in = ? WHERE id = ?')
            ->execute([$checkIn->format('H:i:s'), $this->unidadId]);
        $this->pdo()->prepare('UPDATE reservas SET fecha_ingreso = ?, fecha_egreso = ? WHERE id = ?')
            ->execute([$checkIn->format('Y-m-d'), $checkIn->modify('+3 days')->format('Y-m-d'), $reservaId]);

        return $reservaId;
    }

    //El estado de la reserva en la base y su no_show_en: ['no_show', '2026-11-14 17:00:00']
    private function estadoDe(int $reservaId): array
    {
        $consulta = $this->pdo()->prepare('SELECT estado, no_show_en FROM reservas WHERE id = ?');
        $consulta->execute([$reservaId]);
        return array_values($consulta->fetch(\PDO::FETCH_ASSOC));
    }

    //"2026-11-14 17:00:00" (UTC, como en la base) → "2026-11-16T17:00:00Z": las 48 hs, como las devuelve la API
    private function mas48Horas(string $utc): string
    {
        return str_replace(' ', 'T', (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->modify('+48 hours')->format('Y-m-d H:i:s')) . 'Z';
    }

    //Corre scripts/marcar-no-shows.php --marcar contra la base de prueba y devuelve lo que imprimió
    private function marcarNoShows(): string
    {
        $db = \Tests\Soporte\EntornoDePrueba::datosBase();
        $entorno = array_merge(getenv(), [
            'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_USER' => $db['user'],
            'DB_PASS' => $db['pass'], 'DB_NAME' => $db['name'],
        ]);
        $errores = tempnam(sys_get_temp_dir(), 'script');//los logs a un archivo, para no trabar las tuberías
        $proceso = proc_open(
            [PHP_BINARY, RAIZ . '/scripts/marcar-no-shows.php', '--marcar'],
            [1 => ['pipe', 'w'], 2 => ['file', $errores, 'w']],
            $tuberias,
            RAIZ,
            $entorno
        );
        $salida = stream_get_contents($tuberias[1]);
        $codigo = proc_close($proceso);
        @unlink($errores);
        $this->assertSame(0, $codigo, "El script de no-shows falló: $salida");
        return $salida;
    }

    //El ingreso ya registrado de la reserva, como lo habría dejado la operadora (por la API ya no se podría:
    //a esta reserva se le pasó el plazo)
    private function registrarIngresoEnBase(int $reservaId): void
    {
        $operador = $this->crearUsuarioEnBase('operador');
        $hex = bin2hex(random_bytes(16));
        $uuid = implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
        $this->pdo()->prepare(
            'INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id)
             VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)'
        )->execute([$reservaId, $operador['id'], $uuid]);
    }

    //Las reservas y las canceladas de la unidad, como las ve el propietario
    private function reservasDeLaUnidad(): array
    {
        $respuesta = $this->dueno()->get('/unidades/reservas', ['id' => $this->unidadId]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json();
    }

    //Esa reserva, como la ve el huésped en Mis reservas
    private function reservaDelHuesped(ClienteApi $huesped, int $reservaId): array
    {
        $porId = array_column($huesped->get('/reservas/mias')->json()['reservas'], null, 'id');
        $this->assertArrayHasKey($reservaId, $porId, 'el huésped no ve su reserva');
        return $porId[$reservaId];
    }

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

    //Reserva 3 noches ($210.000, seña $42.000) desde $dias días adelante. Devuelve el id.
    private function reservar(ClienteApi $cliente, int $dias): int
    {
        $respuesta = $cliente->post('/reservas/crear', [
            'unidad_id' => $this->unidadId,
            'ingreso'   => $this->fecha("+$dias days"),
            'egreso'    => $this->fecha('+' . ($dias + 3) . ' days'),
            'personas'  => 2,
            'acepta_politica' => true,
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

    //Genera las liquidaciones pendientes como lo hace la plataforma
    private function generar(): void
    {
        $backoffice = $this->iniciarSesion($this->crearUsuarioEnBase('backoffice')['mail']);
        $respuesta = $backoffice->post('/liquidaciones/generar');
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
    }
}
