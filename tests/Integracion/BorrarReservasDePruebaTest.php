<?php

namespace Tests\Integracion;

//El script que borra todas las reservas para pasar al contrato nuevo (scripts/borrar-reservas-de-prueba.php,
//decisión #76): sin las dos opciones no borra nada; con ellas borra las reservas, sus pagos y sus grupos, y deja
//las unidades y las unidades grupales.
final class BorrarReservasDePruebaTest extends CasoDeIntegracion
{
    use EscenaGrupal;

    //Sin --borrar --son-todas-de-prueba solo muestra el resumen; con las dos, borra todo lo de las reservas
    public function testBorraTodasLasReservasSoloConLasDosOpciones(): void
    {
        // Arrange: una reserva grupal con la seña pagada (dos reservas, dos pagos y un grupo)
        $escena = $this->escena();
        $grupalId = $this->crearGrupal($escena);
        [$huesped] = $this->registrar();
        $parametros = $this->parametros($escena, 10);
        $reserva = $huesped->post('/reservas/grupal/crear', [
            'grupal_id' => $grupalId, 'ingreso' => $parametros['ingreso'], 'egreso' => $parametros['egreso'], 'personas' => 10,
            'unidad_ids' => $escena['unidades'], 'acepta_politica' => true,
        ]);
        $this->assertSame(201, $reserva->codigo, $reserva->cuerpo);
        $grupoId = $reserva->json()['grupo']['id'];
        $pago = $huesped->post('/pagos/grupo/confirmar', ['grupo_id' => $grupoId, 'tx_hash' => '0x' . str_pad('7', 64, '0', STR_PAD_LEFT), 'tipo' => 'sena']);
        $this->assertSame(200, $pago->codigo, $pago->cuerpo);

        // Act 1: sin las opciones
        [$codigoResumen, $resumen] = $this->correrScript([]);
        [, $soloUna] = $this->correrScript(['--borrar']);

        // Assert 1: no se borró nada
        $this->assertSame(0, $codigoResumen, $resumen);
        $this->assertStringContainsString('No se borró nada', $resumen);
        $this->assertStringContainsString('No se borró nada', $soloUna);
        $this->assertGreaterThan(0, $this->contar('reservas'));

        // Act 2: con las dos
        [$codigo, $salida] = $this->correrScript(['--borrar', '--son-todas-de-prueba']);

        // Assert 2: no quedan reservas, pagos ni grupos; las unidades y la unidad grupal siguen
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Se borraron', $salida);
        foreach (['reservas', 'pagos', 'grupos_reserva', 'liquidaciones', 'estadias'] as $tabla) {
            $this->assertSame(0, $this->contar($tabla), $tabla);
        }
        $this->assertSame(1, $this->contar('unidades_grupales', "id = $grupalId"));
        $this->assertSame(3, $this->contar('unidades', 'id IN (' . implode(', ', $escena['unidades']) . ')'));
    }

    // ---------- Ayudas de este test ----------

    //Cuántas filas tiene una tabla (con una condición opcional)
    private function contar(string $tabla, string $condicion = '1 = 1'): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM $tabla WHERE $condicion")->fetchColumn();
    }

    //Corre el script con esas opciones contra la base de prueba. Devuelve [código de salida, lo que imprimió].
    private function correrScript(array $opciones): array
    {
        $db = \Tests\Soporte\EntornoDePrueba::datosBase();
        $entorno = array_merge(getenv(), [
            'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_USER' => $db['user'], 'DB_PASS' => $db['pass'], 'DB_NAME' => $db['name'],
        ]);
        $errores = tempnam(sys_get_temp_dir(), 'script');//los logs a un archivo, para no trabar las tuberías
        $proceso = proc_open([PHP_BINARY, RAIZ . '/scripts/borrar-reservas-de-prueba.php', ...$opciones],
            [1 => ['pipe', 'w'], 2 => ['file', $errores, 'w']], $tuberias, RAIZ, $entorno);
        $salida = stream_get_contents($tuberias[1]);
        $codigo = proc_close($proceso);
        @unlink($errores);
        return [$codigo, $salida];
    }
}
