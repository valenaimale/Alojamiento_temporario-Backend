<?php

namespace Tests\Integracion;

use Tests\Soporte\Datos;

//Las liquidaciones de cada estadía: el listado del propietario (RF-13), las comisiones del administrador (RF-09)
//y los dos comprobantes sin valor fiscal (RF-19, RN-25).
//La cuenta en sí se prueba sin base en Tests\Unitarios\LiquidacionTest; acá se prueba el circuito completo:
//quién puede generar, quién ve qué y qué queda guardado.
final class LiquidacionesTest extends CasoDeIntegracion
{
    //Una estadía de una propietaria que discrimina IVA, con administrador al 10 %: los números del contador
    public function testLiquidaUnaEstadiaConIvaYAdministrador(): void
    {
        $escena = $this->escena(['cobra_iva' => true, 'comision' => 10, 'precio' => 85000]);

        $this->generar();

        $liquidacion = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->assertSame('pendiente', $liquidacion['estado']);
        $this->assertTrue($liquidacion['cobra_iva']);
        $this->assertSame('425000.00', $liquidacion['importe_cobrado']);
        $this->assertSame('21.00', $liquidacion['porcentaje_iva']);
        $this->assertSame('73760.33', $liquidacion['importe_iva']);
        $this->assertSame('351239.67', $liquidacion['base_comisiones']);
        $this->assertSame('26342.98', $liquidacion['comision_plataforma']);
        $this->assertSame('35123.97', $liquidacion['comision_administrador']);
        $this->assertSame('363533.05', $liquidacion['importe_propietario']);
        $this->assertNull($liquidacion['faltante']);
        $this->assertSame($escena['reserva'], $liquidacion['reserva']['id']);
        $this->assertSame(5, $liquidacion['reserva']['noches']);
    }

    //La misma plata, pero de una monotributista y sin administrador: las comisiones van sobre el total (RN-24)
    public function testLiquidaUnaEstadiaSinIvaYDeAutogestion(): void
    {
        $escena = $this->escena(['cobra_iva' => false, 'comision' => null, 'precio' => 85000]);

        $this->generar();

        $liquidacion = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->assertFalse($liquidacion['cobra_iva']);
        $this->assertSame('0.00', $liquidacion['porcentaje_iva']);
        $this->assertSame('0.00', $liquidacion['importe_iva']);
        $this->assertSame('425000.00', $liquidacion['base_comisiones']);
        $this->assertSame('31875.00', $liquidacion['comision_plataforma']);
        $this->assertNull($liquidacion['administrador']);
        $this->assertSame('0.00', $liquidacion['comision_administrador']);
        $this->assertSame('393125.00', $liquidacion['importe_propietario']);
    }

    //RN-16: si falta cobrar parte del saldo, la liquidación se calcula igual pero queda retenida,
    //y el propietario ve cuánto falta
    public function testUnaEstadiaConSaldoSinCobrarQuedaRetenidaPorDeuda(): void
    {
        $escena = $this->escena(['cobra_iva' => false, 'comision' => null, 'precio' => 85000, 'pagar_saldo' => false]);

        $this->generar();

        $liquidacion = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->assertSame('retenida_por_deuda', $liquidacion['estado']);
        $this->assertSame('85000.00', $liquidacion['importe_cobrado']);   //solo la seña del 20 %
        $this->assertSame('340000.00', $liquidacion['faltante']);
    }

    //Una reserva en la que el huésped todavía no ingresó no se liquida: el contrato todavía retiene la plata
    public function testNoLiquidaUnaReservaSinIngresoRegistrado(): void
    {
        $escena = $this->escena(['registrar_ingreso' => false]);

        $this->generar();

        $this->assertSame([], $this->liquidacionesDe($escena['propietario']['mail']));
    }

    //Un no-show se liquida como una cancelación del huésped con todo pagado (#67): se reparte lo que se retuvo,
    //nunca queda en deuda y la respuesta lo marca con "motivo": "no_show"
    public function testUnNoShowSeLiquidaComoUnaCancelacionDelHuesped(): void
    {
        //la seña pagada, el ingreso nunca registrado y el no-show marcado hace más de 48 hs: ya no se espera la excepción
        $escena = $this->escena(['pagar_saldo' => false, 'registrar_ingreso' => false]);
        $this->pdo()->exec(
            "UPDATE reservas SET estado = 'no_show', no_show_en = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = {$escena['reserva']}"
        );

        $this->generar();

        $liquidacion = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->assertSame('no_show', $liquidacion['motivo']);
        $this->assertSame('pendiente', $liquidacion['estado']);   //lo retenido es todo lo que corresponde: no hay deuda
        $this->assertSame('85000.00', $liquidacion['importe_cobrado']);   //solo la seña del 20 %
        $this->assertSame('65875.00', $liquidacion['importe_propietario']);
        $this->assertNull($liquidacion['faltante']);
    }

    //Mientras corren las 48 hs desde el no-show, lo retenido no se liquida: sigue en el contrato por si el
    //propietario le da una excepción al huésped (#64 y #67)
    public function testUnNoShowNoSeLiquidaAntesDeLas48Horas(): void
    {
        $escena = $this->escena(['pagar_saldo' => false, 'registrar_ingreso' => false]);
        $this->pdo()->exec(
            "UPDATE reservas SET estado = 'no_show', no_show_en = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id = {$escena['reserva']}"
        );

        $this->generar();

        $this->assertSame([], $this->liquidacionesDe($escena['propietario']['mail']));
    }

    //Una extensión es una reserva propia y se liquida con el ingreso de la reserva que extiende (#71): ese ingreso
    //ya está registrado cuando se pide la extensión. Queda su propia liquidación, marcada como "extension".
    public function testUnaExtensionSeLiquidaConElIngresoDeLaReservaQueExtiende(): void
    {
        $escena = $this->escena(['cobra_iva' => false, 'comision' => 15, 'precio' => 85000]);
        $this->crearExtensionEnBase($escena['unidad'], $escena['huesped']['id'], $escena['reserva'], $this->importe(170000));

        $this->generar();

        $porMotivo = array_column($this->liquidacionesDe($escena['propietario']['mail']), null, 'motivo');
        $this->assertArrayHasKey('extension', $porMotivo, 'la extensión no se liquidó');
        $this->assertArrayHasKey('estadia', $porMotivo, 'la reserva original tiene su propia liquidación');
        $extension = $porMotivo['extension'];
        //se reparten las dos noches extra, no el total de la estadía, y el propietario ve a qué reserva se le agregaron
        $this->assertSame('170000.00', $extension['importe_cobrado']);
        $this->assertSame('144500.00', $extension['importe_propietario']);//170.000 − 7,5 % − 15 %
        $this->assertSame($escena['reserva'], $extension['reserva']['extiende_reserva_id']);
        $this->assertSame(2, $extension['reserva']['noches']);
        $this->assertNull($extension['faltante']);
    }

    //Generar dos veces no duplica: una estadía se liquida una sola vez
    public function testGenerarDosVecesNoDuplicaLaLiquidacion(): void
    {
        $escena = $this->escena();

        $primera = $this->generar();
        $segunda = $this->generar();

        $this->assertGreaterThanOrEqual(1, $primera['generadas']);
        $this->assertSame(0, $segunda['generadas']);
        $this->assertCount(1, $this->liquidacionesDe($escena['propietario']['mail']));
    }

    //El administrador ve su comisión por cada estadía de las unidades que administra (RF-09)
    public function testElAdministradorVeSuComision(): void
    {
        $escena = $this->escena(['cobra_iva' => true, 'comision' => 10, 'precio' => 85000]);
        $this->generar();

        $respuesta = $this->iniciarSesion($escena['administrador']['mail'])->get('/administrador/comisiones');

        $this->assertSame(200, $respuesta->codigo);
        $comisiones = $respuesta->json()['comisiones'];
        $this->assertCount(1, $comisiones);
        $this->assertSame('351239.67', $comisiones[0]['base_comisiones']);
        $this->assertSame('35123.97', $comisiones[0]['comision']);
        $this->assertSame($escena['propietario']['nombre'], $comisiones[0]['propietario']);
        //su comisión la factura aparte: no ve el importe final del propietario
        $this->assertArrayNotHasKey('importe_propietario', $comisiones[0]);
    }

    //Cada uno ve solo lo suyo: el propietario de otra unidad no ve esta liquidación
    public function testUnPropietarioNoVeLasLiquidacionesDeOtro(): void
    {
        $escena = $this->escena();
        $this->generar();
        $ajeno = $this->crearPropietarioEnBase();

        $this->assertSame([], $this->liquidacionesDe($ajeno['mail']));
        $this->assertCount(1, $this->liquidacionesDe($escena['propietario']['mail']));
    }

    //El comprobante del propietario trae todo lo que pidió el contador (RN-25)
    public function testElComprobanteDelPropietarioTraeElRepartoCompleto(): void
    {
        $escena = $this->escena(['cobra_iva' => true, 'comision' => 10, 'precio' => 85000]);
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];

        $respuesta = $this->iniciarSesion($escena['propietario']['mail'])->get('/liquidaciones/comprobante', ['id' => $id]);

        $this->assertSame(200, $respuesta->codigo);
        $comprobante = $respuesta->json()['comprobante'];
        $this->assertSame('propietario', $comprobante['para']);
        $this->assertTrue($comprobante['sin_valor_fiscal']);
        $this->assertSame($escena['propietario']['nombre'], $comprobante['propietario']['nombre']);
        $this->assertSame($escena['cuit'], $comprobante['propietario']['cuit']);
        $this->assertSame('425000.00', $comprobante['importe_cobrado']);
        $this->assertSame('73760.33', $comprobante['importe_iva']);
        $this->assertSame('26342.98', $comprobante['comision_plataforma']);
        $this->assertSame('35123.97', $comprobante['comision_administrador']);
        $this->assertSame('363533.05', $comprobante['importe_propietario']);
        //el comprobante nombra al administrador, porque su comisión sale de esta liquidación
        $this->assertSame($escena['administrador']['nombre'], $comprobante['administrador']['nombre']);
    }

    //El del administrador trae su comisión con SU condición fiscal, para facturarla aparte (RF-19),
    //y no los datos fiscales ni el importe final del propietario
    public function testElComprobanteDelAdministradorTraeSoloSuComision(): void
    {
        $escena = $this->escena(['cobra_iva' => true, 'comision' => 10, 'precio' => 85000]);
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];

        $respuesta = $this->iniciarSesion($escena['administrador']['mail'])->get('/liquidaciones/comprobante', ['id' => $id]);

        $this->assertSame(200, $respuesta->codigo);
        $comprobante = $respuesta->json()['comprobante'];
        $this->assertSame('administrador', $comprobante['para']);
        $this->assertSame('35123.97', $comprobante['comision']);
        $this->assertSame('10.00', $comprobante['administrador']['porcentaje']);
        $this->assertSame($escena['administrador']['nombre'], $comprobante['administrador']['nombre']);
        $this->assertArrayNotHasKey('importe_propietario', $comprobante);
        $this->assertArrayNotHasKey('cuit', $comprobante['propietario']);
    }

    //El comprobante de otro "no existe": así nadie averigua importes ajenos probando números
    public function testElComprobanteDeOtroDevuelve404(): void
    {
        $escena = $this->escena();
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];
        $ajeno = $this->crearPropietarioEnBase();

        $respuesta = $this->iniciarSesion($ajeno['mail'])->get('/liquidaciones/comprobante', ['id' => $id]);

        $this->assertSame(404, $respuesta->codigo);
        $this->assertSame('No encontramos ese comprobante', $respuesta->json()['error']);
    }

    //Un huésped no tiene comprobantes que ver
    public function testUnHuespedNoPuedeVerComprobantes(): void
    {
        [$cliente] = $this->registrar('huesped');

        $respuesta = $cliente->get('/liquidaciones/comprobante', ['id' => 1]);

        $this->assertSame(403, $respuesta->codigo);
    }

    //Liquidar es tarea de la plataforma: el propietario no puede generar sus propias liquidaciones
    public function testSoloElBackofficePuedeGenerarYPagar(): void
    {
        $escena = $this->escena();
        $propietario = $this->iniciarSesion($escena['propietario']['mail']);

        $this->assertSame(403, $propietario->post('/liquidaciones/generar')->codigo);
        $this->assertSame(403, $propietario->post('/liquidaciones/pagar', ['liquidacion_id' => 1])->codigo);
        $this->assertSame(401, $this->cliente()->post('/liquidaciones/generar')->codigo);
    }

    //Pagar una liquidación la deja pagada, con la fecha, y no se puede pagar dos veces
    public function testPagarUnaLiquidacionLaDejaPagadaUnaSolaVez(): void
    {
        $escena = $this->escena();
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];
        $backoffice = $this->sesionDeBackoffice();

        $primera = $backoffice->post('/liquidaciones/pagar', ['liquidacion_id' => $id]);
        $segunda = $backoffice->post('/liquidaciones/pagar', ['liquidacion_id' => $id]);

        $this->assertSame(200, $primera->codigo);
        $this->assertSame(409, $segunda->codigo);
        $liquidacion = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->assertSame('pagada', $liquidacion['estado']);
        $this->assertNotNull($liquidacion['liquidada_en']);
    }

    //Una liquidación retenida por deuda no se paga: la plataforma no adelanta plata que no cobró (RN-16)
    public function testNoSePuedePagarUnaLiquidacionRetenidaPorDeuda(): void
    {
        $escena = $this->escena(['pagar_saldo' => false]);
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];

        $respuesta = $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $id]);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertStringContainsString('retenida', $respuesta->json()['error']);
    }

    //Sin la billetera de cobro del propietario no se liquida: el ETH se queda en el contrato hasta que
    //la cargue, porque no hay a dónde mandarle su parte (#44). La liquidación sigue pendiente.
    public function testNoSePuedePagarSinLaBilleteraDelPropietario(): void
    {
        $escena = $this->escena();
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];
        $this->borrarBilleteraEnBase('propietarios', $escena['propietario']['id']);

        $respuesta = $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $id]);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertStringContainsString('billetera', $respuesta->json()['error']);
        $this->assertSame('pendiente', $this->liquidacionesDe($escena['propietario']['mail'])[0]['estado']);
    }

    //Tampoco se liquida si el que no cargó la billetera es el administrador: su comisión también sale
    //del contrato, y liquidar() manda las dos partes en la misma transacción
    public function testNoSePuedePagarSinLaBilleteraDelAdministrador(): void
    {
        $escena = $this->escena(['comision' => 10]);
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];
        $this->borrarBilleteraEnBase('administradores', $escena['administrador']['id']);

        $respuesta = $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $id]);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertStringContainsString('administrador', $respuesta->json()['error']);
    }

    //En una unidad de autogestión no hay administrador: con la billetera del propietario alcanza para liquidar
    public function testUnaUnidadDeAutogestionSeLiquidaConLaBilleteraDelPropietario(): void
    {
        $escena = $this->escena(['comision' => null]);
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];

        $respuesta = $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $id]);

        $this->assertSame(200, $respuesta->codigo);
    }

    //El propietario ve su billetera y, si no la cargó, que es lo que falta para que le transfieran (#44)
    public function testLaLiquidacionDiceQuienNoCargoSuBilletera(): void
    {
        $escena = $this->escena(['comision' => 10]);
        $this->generar();

        $conTodo = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->borrarBilleteraEnBase('propietarios', $escena['propietario']['id']);
        $sinBilletera = $this->liquidacionesDe($escena['propietario']['mail'])[0];

        $this->assertSame([], $conTodo['faltan_billeteras']);
        $this->assertNotNull($conTodo['billetera']);
        $this->assertSame(['propietario'], $sinBilletera['faltan_billeteras']);
        $this->assertNull($sinBilletera['billetera']);
    }

    //El administrador también ve la suya en sus comisiones
    public function testElAdministradorVeSuBilleteraEnSusComisiones(): void
    {
        $escena = $this->escena(['comision' => 10]);
        $this->generar();
        $administrador = $this->iniciarSesion($escena['administrador']['mail']);

        $comision = $administrador->get('/administrador/comisiones')->json()['comisiones'][0];

        $this->assertNotNull($comision['billetera']);
        $this->assertSame([], $comision['faltan_billeteras']);
    }

    //Una liquidación que no existe da 404, y un id que no es un número también
    public function testPagarAlgoQueNoExisteDevuelve404(): void
    {
        $backoffice = $this->sesionDeBackoffice();

        $this->assertSame(404, $backoffice->post('/liquidaciones/pagar', ['liquidacion_id' => 999999])->codigo);
        $this->assertSame(404, $backoffice->post('/liquidaciones/pagar', ['liquidacion_id' => 'hola'])->codigo);
    }

    // ---------- Pagar con el contrato (decisiones #57 a #61) ----------

    //Pagar manda UNA transacción liquidar() con las tres partes: cada una a su billetera, en wei, en la proporción de
    //los pesos, y entre todas exactamente lo que entró al contrato para esa reserva
    public function testPagarMandaLiquidarConElRepartoEnWei(): void
    {
        $escena = $this->escena(['cobra_iva' => true, 'comision' => 10, 'precio' => 85000]);
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];

        $respuesta = $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $id]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $respuesta->json()['tx_hash']);
        $pagos = $this->pagosDeLiquidacion($escena['reserva']);
        $this->assertSame([
            [$this->billeteraDe('propietarios', $escena['propietario']['id']), '363533.05', '3635330500000000'],
            [$this->billeteraDe('administradores', $escena['administrador']['id']), '35123.97', '351239700000000'],
            ['0x0000000000000000000000000000000000031337', '26342.98', '263429800000000'],//la plataforma (firmador simulado)
        ], array_map(fn ($p) => [$p['billetera'], $p['monto_pesos'], $p['monto_wei']], $pagos));
        $this->assertSame([$respuesta->json()['tx_hash']], array_values(array_unique(array_column($pagos, 'tx_hash'))));
        $this->assertSame(['confirmado'], array_values(array_unique(array_column($pagos, 'estado'))));

        $liquidacion = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->assertSame('pagada', $liquidacion['estado']);
        $this->assertSame('estadia', $liquidacion['motivo']);
        $this->assertSame(['tx_hash' => $respuesta->json()['tx_hash'], 'red' => 31337, 'simulado' => true], $liquidacion['transaccion']);
    }

    //Si el propietario es también el administrador (la misma billetera), su parte y su comisión van juntas:
    //una transacción no puede tener dos pagos a la misma billetera
    public function testLasPartesDeLaMismaBilleteraSeSuman(): void
    {
        $escena = $this->escena(['comision' => 10]);
        $this->pdo()->prepare('UPDATE administradores SET billetera = ? WHERE usuario_id = ?')
            ->execute([$this->billeteraDe('propietarios', $escena['propietario']['id']), $escena['administrador']['id']]);
        $this->generar();
        $id = $this->liquidacionesDe($escena['propietario']['mail'])[0]['id'];

        $this->assertSame(200, $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $id])->codigo);

        $pagos = $this->pagosDeLiquidacion($escena['reserva']);
        $this->assertCount(2, $pagos);
        $this->assertSame('425000.00', bcadd($pagos[0]['monto_pesos'], $pagos[1]['monto_pesos'], 2));
        $this->assertSame('4250000000000000', bcadd($pagos[0]['monto_wei'], $pagos[1]['monto_wei'], 0));
    }

    //Una cancelada en la que la política retuvo parte de la seña se liquida sobre lo retenido, igual que una estadía
    //(decisión #57): no está en deuda y se puede pagar
    public function testUnaCanceladaConRetencionSeLiquidaSobreLoRetenido(): void
    {
        $escena = $this->escenaCancelada('59500.00');//de una seña de $85.000 se devuelve el 70 %

        $this->generar();

        $liquidacion = $this->liquidacionesDe($escena['propietario']['mail'])[0];
        $this->assertSame('cancelacion', $liquidacion['motivo']);
        $this->assertSame('pendiente', $liquidacion['estado']);
        $this->assertSame('25500.00', $liquidacion['importe_cobrado']);
        $this->assertSame('1912.50', $liquidacion['comision_plataforma']);   //7,5 %
        $this->assertSame('3825.00', $liquidacion['comision_administrador']); //15 %
        $this->assertSame('19762.50', $liquidacion['importe_propietario']);
        $this->assertNull($liquidacion['faltante']);

        $this->assertSame(200, $this->sesionDeBackoffice()->post('/liquidaciones/pagar', ['liquidacion_id' => $liquidacion['id']])->codigo);
        //sale exactamente lo que quedó en el contrato: la seña menos la devolución
        $this->assertSame('255000000000000', array_reduce($this->pagosDeLiquidacion($escena['reserva']), fn ($s, $p) => bcadd($s, $p['monto_wei'], 0), '0'));
    }

    //Mientras la devolución no se confirma, no se sabe cuánto queda en el contrato: todavía no se liquida
    public function testUnaCanceladaConLaDevolucionEnCaminoTodaviaNoSeLiquida(): void
    {
        $escena = $this->escenaCancelada('59500.00', 'pendiente');

        $this->generar();
        $this->assertSame([], $this->liquidacionesDe($escena['propietario']['mail']));

        $this->pdo()->prepare(
            "UPDATE pagos SET estado = 'confirmado', tx_hash = ?, confirmado_en = UTC_TIMESTAMP() WHERE reserva_id = ? AND tipo = 'devolucion'"
        )->execute(['0x' . bin2hex(random_bytes(32)), $escena['reserva']]);
        $this->generar();
        $this->assertCount(1, $this->liquidacionesDe($escena['propietario']['mail']));
    }

    //Si se devolvió toda la seña (por ejemplo, la política no retenía nada), no hay nada que liquidar
    public function testUnaCanceladaSinRetencionNoSeLiquida(): void
    {
        $escena = $this->escenaCancelada('85000.00');

        $this->generar();

        $this->assertSame([], $this->liquidacionesDe($escena['propietario']['mail']));
    }

    //El cron de las tareas frecuentes (scripts/pagar-liquidaciones.php) paga solo las pendientes, sin el backoffice
    public function testElScriptPagaLasLiquidacionesPendientes(): void
    {
        $escena = $this->escena(['comision' => null]);
        $this->generar();

        [$codigo, $salida] = $this->correrScript('pagar-liquidaciones.php');

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString("Reserva {$escena['reserva']}: Liquidación pagada", $salida);
        $this->assertSame('pagada', $this->liquidacionesDe($escena['propietario']['mail'])[0]['estado']);
        $this->assertCount(2, $this->pagosDeLiquidacion($escena['reserva']));//propietario y plataforma, sin administrador

        //una segunda corrida no la vuelve a pagar
        $this->correrScript('pagar-liquidaciones.php');
        $this->assertCount(2, $this->pagosDeLiquidacion($escena['reserva']));
    }

    // ---------- Ayudas de este test ----------

    //Una reserva con la seña pagada que se canceló antes de ingresar, con la devolución de $devolucion
    //('confirmado', o 'pendiente' si todavía no salió). Devuelve la escena.
    private function escenaCancelada(string $devolucion, string $estado = 'confirmado'): array
    {
        $escena = $this->escena(['pagar_saldo' => false, 'registrar_ingreso' => false]);
        $this->pdo()->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = ?")->execute([$escena['reserva']]);
        $this->pdo()->prepare(
            "INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, confirmado_en)
             VALUES (?, 'devolucion', ?, ?, '100000000', ?, 31337, ?, ?, ?)"
        )->execute([
            $escena['reserva'], $devolucion, bcmul($devolucion, '10000000000', 0), '0x' . bin2hex(random_bytes(20)),
            $estado === 'confirmado' ? '0x' . bin2hex(random_bytes(32)) : null, $estado,
            $estado === 'confirmado' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        return $escena;
    }

    //Los pagos de tipo 'liquidacion' de la reserva, en el orden en que se anotaron
    private function pagosDeLiquidacion(int $reservaId): array
    {
        $consulta = $this->pdo()->prepare(
            "SELECT billetera, monto_pesos, monto_wei, tx_hash, estado FROM pagos WHERE reserva_id = ? AND tipo = 'liquidacion' ORDER BY id"
        );
        $consulta->execute([$reservaId]);
        return $consulta->fetchAll();
    }

    //La billetera de cobro de un propietario o administrador, en minúsculas (como la anota el pago)
    private function billeteraDe(string $tabla, int $usuarioId): string
    {
        $consulta = $this->pdo()->prepare("SELECT billetera FROM {$tabla} WHERE usuario_id = ?");
        $consulta->execute([$usuarioId]);
        return strtolower((string) $consulta->fetchColumn());
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

    //Arma una estadía lista para liquidar: propietaria, administradora (opcional), operadora, huésped, unidad
    //publicada con precios, reserva de 5 noches ya terminada, la seña y el saldo pagados y el ingreso registrado.
    //Opciones: cobra_iva, comision (null = autogestión), precio por noche, pagar_saldo, registrar_ingreso.
    private function escena(array $opciones = []): array
    {
        $opciones = array_merge([
            'cobra_iva'         => false,
            'comision'          => 15,
            'precio'            => 85000,
            'pagar_saldo'       => true,
            'registrar_ingreso' => true,
        ], $opciones);

        $propietario = $this->crearPropietarioEnBase();
        $cuit = null;
        if ($opciones['cobra_iva']) {
            $cuit = Datos::cuit();
            $this->pdo()->prepare(
                'UPDATE propietarios SET cobra_iva = 1, cuit = ?, razon_social = ?, domicilio_fiscal = ? WHERE usuario_id = ?'
            )->execute([$cuit, $propietario['nombre'], 'Av. 3 n.º 1250, Villa Gesell', $propietario['id']]);
        }
        $operador = $this->crearUsuarioEnBase('operador');
        $huesped = $this->crearUsuarioEnBase('huesped');

        $unidad = $this->crearUnidadEnBase($propietario['id'], ['operador_id' => $operador['id']]);
        $administrador = null;
        if ($opciones['comision'] !== null) {
            $administrador = $this->crearAdministradorEnBase();
            $this->asignarAdministradorEnBase($unidad, $administrador['id'], (float) $opciones['comision']);
        }

        //cinco noches que ya pasaron, con su precio cargado
        $ingreso = $this->fecha('-12 days');
        $egreso = $this->fecha('-7 days');
        $this->cargarPreciosEnBase($unidad, $ingreso, $this->fecha('-8 days'), (float) $opciones['precio'], $propietario['id']);

        $total = $this->importe($opciones['precio'] * 5);
        $sena = $this->importe($opciones['precio'] * 5 * 0.2);   //la seña por defecto de la unidad es el 20 %
        $reserva = $this->crearReservaEnBase($unidad, $huesped['id'], $ingreso, $egreso, $total, $sena);

        $this->pagarEnBase($reserva, 'sena', $sena);
        if ($opciones['pagar_saldo']) {
            $this->pagarEnBase($reserva, 'saldo', $this->importe($opciones['precio'] * 5 * 0.8));
        }
        if ($opciones['registrar_ingreso']) {
            $this->registrarEstadiaEnBase($reserva, $operador['id'], $ingreso, $egreso);
        }

        return [
            'propietario'   => $propietario,
            'administrador' => $administrador,
            'operador'      => $operador,
            'huesped'       => $huesped,
            'unidad'        => $unidad,
            'reserva'       => $reserva,
            'cuit'          => $cuit,
        ];
    }

    //Una reserva confirmada de esas fechas. Devuelve su id.
    private function crearReservaEnBase(int $unidadId, int $huespedId, string $ingreso, string $egreso, string $total, string $sena): int
    {
        $this->pdo()->prepare(
            "INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado,
                                   importe_total, porcentaje_sena, importe_sena,
                                   retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15,
                                   politica_aceptada_en)
             VALUES (?, ?, ?, ?, 2, 'confirmada', ?, 20, ?, 30, 50, 70, UTC_TIMESTAMP())"
        )->execute([$unidadId, $huespedId, $ingreso, $egreso, $total, $sena]);
        return (int) $this->pdo()->lastInsertId();
    }

    //Una extensión paga de la reserva $originalId: las dos noches que siguen a su egreso, en una reserva propia
    //vinculada por extiende_reserva_id, que se paga entera en un solo pago (porcentaje_sena 100, sin saldo: #70).
    //Devuelve su id.
    private function crearExtensionEnBase(int $unidadId, int $huespedId, int $originalId, string $total): int
    {
        $this->pdo()->prepare(
            "INSERT INTO reservas (unidad_id, huesped_id, extiende_reserva_id, fecha_ingreso, fecha_egreso,
                                   cantidad_personas, estado, importe_total, porcentaje_sena, importe_sena, monto_faltante,
                                   retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15, politica_aceptada_en)
             VALUES (?, ?, ?, ?, ?, 2, 'confirmada', ?, 100, ?, 0, 30, 50, 70, UTC_TIMESTAMP())"
        )->execute([$unidadId, $huespedId, $originalId, $this->fecha('-7 days'), $this->fecha('-5 days'), $total, $total]);
        $extensionId = (int) $this->pdo()->lastInsertId();
        $this->pagarEnBase($extensionId, 'sena', $total);//la "seña" de una extensión es el 100 %
        return $extensionId;
    }

    //Un pago ya confirmado de la reserva, con la billetera y el hash con el formato que exigen los CHECK
    private function pagarEnBase(int $reservaId, string $tipo, string $pesos): void
    {
        $this->pdo()->prepare(
            "INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red,
                                tx_hash, estado, confirmado_en)
             VALUES (?, ?, ?, ?, '100000000', ?, 31337, ?, 'confirmado', UTC_TIMESTAMP())"
        )->execute([
            $reservaId,
            $tipo,
            $pesos,
            (string) (int) ((float) $pesos * 10_000_000_000),   //pesos → wei con 100.000.000 pesos por ETH
            '0x' . bin2hex(random_bytes(20)),
            '0x' . bin2hex(random_bytes(32)),
        ]);
    }

    //El ingreso y el egreso de la estadía, como los habría registrado la operadora
    private function registrarEstadiaEnBase(int $reservaId, int $operadorId, string $ingreso, string $egreso): void
    {
        $this->pdo()->prepare(
            'INSERT INTO estadias (reserva_id, ingreso_en, ingreso_registrado_en, ingreso_operador_id, ingreso_evento_id,
                                   egreso_en, egreso_registrado_en, egreso_operador_id, egreso_evento_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $reservaId,
            "$ingreso 18:00:00", "$ingreso 18:10:00", $operadorId, $this->uuid(),
            "$egreso 13:00:00", "$egreso 13:10:00", $operadorId, $this->uuid(),
        ]);
    }

    //Genera las liquidaciones pendientes como lo hace la plataforma, y devuelve lo que respondió la API
    private function generar(): array
    {
        $respuesta = $this->sesionDeBackoffice()->post('/liquidaciones/generar');
        $this->assertSame(200, $respuesta->codigo, "No se pudieron generar las liquidaciones: {$respuesta->cuerpo}");
        return $respuesta->json();
    }

    //Le borra la billetera de cobro, como la tienen los propietarios y administradores que se registraron
    //antes de que existiera la columna y todavía no la cargaron (#44)
    private function borrarBilleteraEnBase(string $tabla, int $usuarioId): void
    {
        $this->pdo()->prepare("UPDATE {$tabla} SET billetera = NULL WHERE usuario_id = ?")->execute([$usuarioId]);
    }

    //Las liquidaciones que ve ese propietario
    private function liquidacionesDe(string $mail): array
    {
        $respuesta = $this->iniciarSesion($mail)->get('/liquidaciones/mias');
        $this->assertSame(200, $respuesta->codigo, "No se pudieron leer las liquidaciones: {$respuesta->cuerpo}");
        return $respuesta->json()['liquidaciones'];
    }

    //Un backoffice nuevo con la sesión iniciada
    private function sesionDeBackoffice(): \Tests\Soporte\ClienteApi
    {
        return $this->iniciarSesion($this->crearUsuarioEnBase('backoffice')['mail']);
    }

    //Un identificador de movimiento con la forma de un UUID (el formato que exige la tabla estadias)
    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    //Un importe con dos decimales y punto, como lo guarda la base
    private function importe(float $monto): string
    {
        return number_format(round($monto, 2), 2, '.', '');
    }
}
