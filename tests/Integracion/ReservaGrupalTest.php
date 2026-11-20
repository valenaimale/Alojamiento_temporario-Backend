<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//La reserva grupal (US4.4 y US8.5): el huésped reserva varias unidades de una grupal, paga la seña y el saldo de todas
//juntas (una transacción del contrato), y cancela cada unidad por separado. Decisiones del 20/11.
//Los pagos usan el verificador simulado: un hash que termina en "dead" falla y cualquier otro se confirma.
final class ReservaGrupalTest extends CasoDeIntegracion
{
    use EscenaGrupal;


    // ---------- Reservar ----------

    //Reserva las tres cabañas para 10 personas: un grupo con una reserva pendiente por cabaña, con el mismo plazo
    public function testReservaLasUnidadesElegidasEnUnGrupo(): void
    {
        // Arrange
        [$escena, $grupalId, $huesped] = $this->preparar();

        // Act
        $respuesta = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId));

        // Assert
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $grupo = $respuesta->json()['grupo'];
        $this->assertSame('630000.00', $grupo['importe_total']);
        $this->assertSame('126000.00', $grupo['importe_sena']);//el 20 % de cada una
        $this->assertSame([4, 4, 2], array_column($grupo['reservas'], 'personas'));
        $filas = $this->reservasDelGrupo($grupo['id']);
        $this->assertCount(3, $filas);
        $this->assertSame(['pendiente'], array_values(array_unique(array_column($filas, 'estado'))));
        $this->assertCount(1, array_unique(array_column($filas, 'bloqueo_expira_en')));
        $this->assertSame($escena['unidades'], array_map('intval', array_column($filas, 'unidad_id')));
    }

    //Puede elegir menos unidades de las que ofrece la grupal, mientras alcancen para todos
    public function testPuedeElegirAlgunasDeLasUnidades(): void
    {
        [$escena, $grupalId, $huesped] = $this->preparar();

        $respuesta = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId, [
            'personas' => 7, 'unidad_ids' => array_slice($escena['unidades'], 1),
        ]));

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([4, 3], array_column($respuesta->json()['grupo']['reservas'], 'personas'));
    }

    //Lo que no se puede elegir: una sola unidad, unidades sin lugar para todos, o una que ya se ocupó
    public function testSoloSePuedeElegirLoQueOfreceLaGrupal(): void
    {
        // Arrange
        [$escena, $grupalId, $huesped] = $this->preparar();
        [$a, $b, $c] = $escena['unidades'];

        // Act
        $unaSola = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId, ['personas' => 5, 'unidad_ids' => [$a]]));
        $sinLugar = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId, ['unidad_ids' => [$a, $b]]));
        $ajena = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId, ['unidad_ids' => [$a, $b, 999999]]));
        $this->crearBloqueoEnBase($c, $this->fecha('+11 days'), $this->fecha('+12 days'), $escena['propietarias'][1]['id']);
        $ocupada = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId, ['personas' => 8]));

        // Assert
        $this->assertSame(422, $unaSola->codigo, $unaSola->cuerpo);
        $this->assertSame(422, $sinLugar->codigo);
        $this->assertSame('Las unidades que elegiste tienen lugar para 8 personas: sumá otra', $sinLugar->json()['error']);
        $this->assertSame(409, $ajena->codigo);
        $this->assertSame(409, $ocupada->codigo);
        $this->assertSame(0, $this->reservasDe($escena));
    }

    //Sin aceptar la política, o sin sesión de huésped, no se reserva
    public function testHaceFaltaAceptarLaPoliticaYSerHuesped(): void
    {
        [$escena, $grupalId, $huesped] = $this->preparar();

        $sinPolitica = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId, ['acepta_politica' => false]));
        $sinSesion = $this->cliente()->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId));

        $this->assertSame(422, $sinPolitica->codigo);
        $this->assertSame(401, $sinSesion->codigo);
    }

    // ---------- Pagar la seña ----------

    //La seña del grupo se paga junta: el back arma los montos de cada reserva para pagarSenaGrupal, y al confirmar
    //quedan las tres confirmadas, con un pago cada una y la misma transacción
    public function testLaSenaDelGrupoSePagaJunta(): void
    {
        // Arrange
        [$escena, $grupoId, $huesped] = $this->grupoReservado();

        // Act
        $datos = $huesped->get('/pagos/grupo/sena', ['grupo_id' => $grupoId]);
        $confirmar = $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('1'), 'tipo' => 'sena']);

        // Assert
        $this->assertSame(200, $datos->codigo, $datos->cuerpo);
        $this->assertSame('126000.00', $datos->json()['monto_pesos']);
        $this->assertSame('pagarSenaGrupal', $datos->json()['pago']['funcion']);
        $this->assertCount(3, $datos->json()['reservas']);
        $this->assertSame($datos->json()['monto_wei'], array_reduce(array_column($datos->json()['reservas'], 'monto_wei'), fn ($s, $w) => bcadd($s, $w, 0), '0'));

        $this->assertSame(200, $confirmar->codigo, $confirmar->cuerpo);
        $this->assertSame(['confirmada'], array_values(array_unique(array_column($this->reservasDelGrupo($grupoId), 'estado'))));
        $pagos = $this->pagosDelGrupo($grupoId, 'sena');
        $this->assertCount(3, $pagos);
        $this->assertSame([$this->tx('1')], array_values(array_unique(array_column($pagos, 'tx_hash'))));
    }

    //Una unidad del grupo no se paga sola: el pago común la rechaza
    public function testUnaUnidadDelGrupoNoSePagaSola(): void
    {
        [, $grupoId, $huesped] = $this->grupoReservado();
        $reservaId = (int) $this->reservasDelGrupo($grupoId)[0]['id'];

        $respuesta = $huesped->get('/pagos/sena', ['reserva_id' => $reservaId]);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Esta reserva es parte de una reserva grupal: se paga junto con las demás unidades', $respuesta->json()['error']);
    }

    //Si la transacción falla, no se confirma ninguna (el contrato es todo o nada) y queda el intento de cada una
    public function testUnPagoFallidoNoConfirmaNinguna(): void
    {
        [, $grupoId, $huesped] = $this->grupoReservado();

        $respuesta = $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('dead'), 'tipo' => 'sena']);

        $this->assertSame(402, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['pendiente'], array_values(array_unique(array_column($this->reservasDelGrupo($grupoId), 'estado'))));
        $this->assertSame(['fallido'], array_values(array_unique(array_column($this->pagosDelGrupo($grupoId, 'sena'), 'estado'))));
    }

    //La misma transacción no se puede usar dos veces, y el grupo de otro "no existe"
    public function testLaTransaccionNoSeReusaYElGrupoDeOtroNoExiste(): void
    {
        [$escena, $grupoId, $huesped] = $this->grupoReservado();
        $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('2'), 'tipo' => 'sena']);
        [$otro] = $this->registrar();

        $repetida = $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('2'), 'tipo' => 'sena']);
        $ajeno = $otro->get('/pagos/grupo/sena', ['grupo_id' => $grupoId]);

        $this->assertSame(409, $repetida->codigo);
        $this->assertSame(404, $ajeno->codigo);
    }

    // ---------- Cancelar una unidad y pagar el saldo ----------

    //Se cancela una unidad (con su política, devolviéndole al mismo huésped) y el saldo se paga junto, sin esa unidad
    public function testElSaldoSePagaJuntoSinLaUnidadCancelada(): void
    {
        // Arrange
        [, $grupoId, $huesped] = $this->grupoReservado();
        $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('3'), 'tipo' => 'sena']);
        $reservas = $this->reservasDelGrupo($grupoId);

        // Act
        $cancelar = $huesped->post('/reservas/cancelar', ['reserva_id' => (int) $reservas[0]['id']]);
        $datos = $huesped->get('/pagos/grupo/saldo', ['grupo_id' => $grupoId]);
        $pagar = $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('4'), 'tipo' => 'saldo']);

        // Assert
        $this->assertSame(200, $cancelar->codigo, $cancelar->cuerpo);
        $this->assertSame(200, $datos->codigo, $datos->cuerpo);
        $this->assertSame('pagarSaldoGrupal', $datos->json()['pago']['funcion']);
        $this->assertSame([(int) $reservas[1]['id'], (int) $reservas[2]['id']], array_column($datos->json()['reservas'], 'reserva_id'));
        $this->assertSame('336000.00', $datos->json()['monto_pesos']);//el 80 % de dos cabañas de $210.000
        $this->assertSame(200, $pagar->codigo, $pagar->cuerpo);
        $despues = $this->reservasDelGrupo($grupoId);
        $this->assertSame('cancelada', $despues[0]['estado']);
        $this->assertSame(['0.00', '0.00'], [$despues[1]['monto_faltante'], $despues[2]['monto_faltante']]);
    }

    //Antes de la seña no se paga el saldo
    public function testElSaldoNoSePagaAntesDeLaSena(): void
    {
        [, $grupoId, $huesped] = $this->grupoReservado();

        $respuesta = $huesped->get('/pagos/grupo/saldo', ['grupo_id' => $grupoId]);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Primero tenés que pagar la seña', $respuesta->json()['error']);
    }

    //Si el saldo vence en la fecha de cobro más temprana del grupo, se cancelan todas las unidades juntas
    public function testElSaldoVencidoCancelaTodoElGrupo(): void
    {
        // Arrange: la seña pagada, y la primera unidad con una fecha de cobro vieja (como si se hubiera corrido de fecha)
        [, $grupoId, $huesped] = $this->grupoReservado();
        $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('5'), 'tipo' => 'sena']);
        $primera = (int) $this->reservasDelGrupo($grupoId)[0]['id'];
        $this->pdo()->prepare('UPDATE reservas SET fecha_cobro = ? WHERE id = ?')->execute([$this->fecha('-20 days'), $primera]);

        // Act
        [$codigo, $salida] = $this->correrScript('cancelar-saldos-vencidos.php');

        // Assert
        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(['cancelada'], array_values(array_unique(array_column($this->reservasDelGrupo($grupoId), 'estado'))));
    }

    //Los recordatorios del saldo de un grupo van en un solo mail, con el total de todas las unidades
    public function testElRecordatorioDelSaldoEsUnoPorGrupo(): void
    {
        // Arrange: la seña pagada y la fecha de cobro de hoy
        [, $grupoId, $huesped] = $this->grupoReservado();
        $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => $this->tx('6'), 'tipo' => 'sena']);
        $this->pdo()->prepare('UPDATE reservas SET fecha_cobro = ? WHERE grupo_id = ?')->execute([$this->fecha(), $grupoId]);
        $ids = array_column($this->reservasDelGrupo($grupoId), 'id');

        // Act
        [$codigo, $salida] = $this->correrScript('enviar-recordatorios.php');

        // Assert: el recordatorio sale para la primera reserva, con la deuda de las tres, y no para las otras dos
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString("reserva {$ids[0]} - ", $salida);
        $this->assertStringContainsString('debe 504000.00', $salida);
        $this->assertStringNotContainsString("reserva {$ids[1]} - ", $salida);
        $this->assertStringNotContainsString("reserva {$ids[2]} - ", $salida);
    }

    // ---------- Cómo se ve ----------

    //En Mis reservas cada unidad dice de qué grupo es; el propietario la ve como grupal y el administrador, además,
    //con qué otras unidades se reservó
    public function testCadaUnoVeLaReservaComoGrupal(): void
    {
        // Arrange
        [$escena, $grupoId, $huesped] = $this->grupoReservado();
        $propietaria = $this->iniciarSesion($escena['propietarias'][1]['mail']);

        // Act
        $mias = $huesped->get('/reservas/mias')->json()['reservas'];
        $dePropietaria = $propietaria->get('/unidades/reservas', ['id' => $escena['unidades'][2]])->json()['reservas'];
        $deAdministrador = $escena['cliente']->get('/unidades/reservas', ['id' => $escena['unidades'][2]])->json()['reservas'];

        // Assert
        $this->assertCount(3, $mias);
        $this->assertSame([['id' => $grupoId, 'titulo' => 'Cabañas del grupo']], array_values(array_unique(array_column($mias, 'grupo'), SORT_REGULAR)));
        $this->assertTrue($dePropietaria[0]['grupal']);
        $this->assertNull($dePropietaria[0]['grupo_unidades']);
        $this->assertCount(2, $deAdministrador[0]['grupo_unidades']);
    }

    // ---------- Ayudas de este test ----------

    //La escena, su grupal y un huésped nuevo (con el mail verificado y el DNI): [escena, id de la grupal, cliente]
    private function preparar(): array
    {
        $escena = $this->escena();
        $grupalId = $this->crearGrupal($escena);
        [$huesped] = $this->registrar();
        return [$escena, $grupalId, $huesped];
    }

    //Lo que manda el huésped para reservar la grupal: las tres cabañas para 10 personas, del +10 al +13
    private function pedido(array $escena, int $grupalId, array $cambios = []): array
    {
        $parametros = $this->parametros($escena, 10);
        return array_merge([
            'grupal_id' => $grupalId, 'ingreso' => $parametros['ingreso'], 'egreso' => $parametros['egreso'],
            'personas' => 10, 'unidad_ids' => $escena['unidades'], 'acepta_politica' => true,
        ], $cambios);
    }

    //Un grupo ya reservado con las tres cabañas: [escena, id del grupo, cliente del huésped]
    private function grupoReservado(): array
    {
        [$escena, $grupalId, $huesped] = $this->preparar();
        $respuesta = $huesped->post('/reservas/grupal/crear', $this->pedido($escena, $grupalId));
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return [$escena, $respuesta->json()['grupo']['id'], $huesped];
    }

    //Un hash de transacción de prueba que termina en $final (el verificador simulado decide por cómo termina)
    private function tx(string $final): string
    {
        return '0x' . str_pad($final, 64, '0', STR_PAD_LEFT);
    }

    //Las reservas de un grupo en la base, por id
    private function reservasDelGrupo(int $grupoId): array
    {
        $consulta = $this->pdo()->prepare('SELECT * FROM reservas WHERE grupo_id = ? ORDER BY id');
        $consulta->execute([$grupoId]);
        return $consulta->fetchAll();
    }

    //Los pagos de ese tipo de las reservas de un grupo
    private function pagosDelGrupo(int $grupoId, string $tipo): array
    {
        $consulta = $this->pdo()->prepare(
            'SELECT p.* FROM pagos p JOIN reservas r ON r.id = p.reserva_id WHERE r.grupo_id = ? AND p.tipo = ? ORDER BY p.id'
        );
        $consulta->execute([$grupoId, $tipo]);
        return $consulta->fetchAll();
    }

    //Cuántas reservas tienen las cabañas de la escena
    private function reservasDe(array $escena): int
    {
        $marcas = implode(', ', array_fill(0, count($escena['unidades']), '?'));
        $consulta = $this->pdo()->prepare("SELECT COUNT(*) FROM reservas WHERE unidad_id IN ($marcas)");
        $consulta->execute($escena['unidades']);
        return (int) $consulta->fetchColumn();
    }

    //Corre un script de scripts/ contra la base de prueba. Devuelve [código de salida, lo que imprimió].
    private function correrScript(string $script): array
    {
        $db = \Tests\Soporte\EntornoDePrueba::datosBase();
        $entorno = array_merge(getenv(), [
            'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_USER' => $db['user'], 'DB_PASS' => $db['pass'], 'DB_NAME' => $db['name'],
        ]);
        $errores = tempnam(sys_get_temp_dir(), 'script');//los logs a un archivo, para no trabar las tuberías
        $proceso = proc_open([PHP_BINARY, RAIZ . "/scripts/$script"], [1 => ['pipe', 'w'], 2 => ['file', $errores, 'w']], $tuberias, RAIZ, $entorno);
        $salida = stream_get_contents($tuberias[1]);
        $codigo = proc_close($proceso);
        @unlink($errores);
        return [$codigo, $salida];
    }
}
