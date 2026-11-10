<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;
use Tests\Soporte\EntornoDePrueba;

//scripts/borrar-reservas-simuladas.php (decisión #53): antes de pasar a los pagos reales se borran las reservas que
//se pagaron en modo simulado (red 31337) y las confirmadas sin una seña real, con todo lo que cuelga de ellas.
//Las demás (pendientes sin pagos, o pagadas en la red real) no se tocan.
final class ReservasSimuladasTest extends CasoDeIntegracion
{
    private const RED_REAL = 84532;

    private int $unidadId;

    //Antes de cada test: una unidad publicada con precio para los próximos 90 días
    protected function setUp(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $this->unidadId = $this->crearUnidadEnBase($propietario['id'], ['estado_publicacion' => 'publicada', 'capacidad' => 4]);
        $this->agregarFotoEnBase($this->unidadId);
        $this->cargarPreciosEnBase($this->unidadId, $this->fecha('+1 day'), $this->fecha('+90 days'), 70000, $propietario['id']);
    }

    //Un huésped con el mail verificado y su sesión iniciada
    private function huesped(): ClienteApi
    {
        $huesped = $this->crearUsuarioEnBase('huesped');
        $this->pdo()->prepare('UPDATE usuarios SET mail_verificado_en = UTC_TIMESTAMP() WHERE id = ?')->execute([$huesped['id']]);
        return $this->iniciarSesion($huesped['mail']);
    }

    //Reserva 3 noches desde $dias días adelante (queda pendiente). Devuelve el id.
    private function reservar(ClienteApi $cliente, int $dias): int
    {
        $respuesta = $cliente->post('/reservas/crear', [
            'unidad_id' => $this->unidadId, 'ingreso' => $this->fecha("+$dias days"), 'egreso' => $this->fecha('+' . ($dias + 3) . ' days'),
            'personas' => 2, 'acepta_politica' => true,
        ]);
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        return $respuesta->json()['reserva']['id'];
    }

    //Paga la seña con el verificador simulado (el pago queda con red 31337)
    private function pagarSimulado(ClienteApi $cliente, int $reservaId, string $final): void
    {
        $hash = '0x' . str_pad($final, 64, '0', STR_PAD_LEFT);
        $respuesta = $cliente->post('/pagos/confirmar', ['reserva_id' => $reservaId, 'tx_hash' => $hash, 'tipo' => 'sena']);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
    }

    //Simula una seña pagada en la red real: el pago confirmado con red 84532 y la reserva confirmada
    private function pagarEnLaRedReal(int $reservaId): void
    {
        $this->pdo()->prepare(
            "INSERT INTO pagos (reserva_id, tipo, monto_pesos, monto_wei, pesos_por_eth, billetera, red, tx_hash, estado, creado_en, confirmado_en)
             VALUES (?, 'sena', 42000, 420000000000000, 100000000, ?, ?, ?, 'confirmado', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$reservaId, '0x' . str_repeat('ab', 20), self::RED_REAL, '0x' . str_repeat('cd', 32)]);
        $this->pdo()->prepare("UPDATE reservas SET estado = 'confirmada', bloqueo_expira_en = NULL WHERE id = ?")->execute([$reservaId]);
    }

    //Cuántas filas tiene una tabla para esa reserva
    private function filas(string $tabla, int $reservaId): int
    {
        $consulta = $this->pdo()->prepare("SELECT COUNT(*) FROM {$tabla} WHERE " . ($tabla === 'reservas' ? 'id' : 'reserva_id') . ' = ?');
        $consulta->execute([$reservaId]);
        return (int) $consulta->fetchColumn();
    }

    //Corre el script contra la base de prueba. Devuelve [código de salida, lo que imprimió].
    private function correrScript(bool $borrar): array
    {
        $db = EntornoDePrueba::datosBase();
        $entorno = array_merge(getenv(), [
            'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_USER' => $db['user'], 'DB_PASS' => $db['pass'], 'DB_NAME' => $db['name'],
        ]);
        $comando = [PHP_BINARY, RAIZ . '/scripts/borrar-reservas-simuladas.php'];
        if ($borrar) {
            $comando[] = '--borrar';
        }
        $errores = tempnam(sys_get_temp_dir(), 'script');//los logs a un archivo, para no trabar las tuberías
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['file', $errores, 'w']], $tuberias, RAIZ, $entorno);
        $salida = stream_get_contents($tuberias[1]);
        $codigo = proc_close($proceso);
        @unlink($errores);
        return [$codigo, $salida];
    }

    //Sin --borrar solo lista; con --borrar se van las simuladas (con sus pagos) y quedan las demás
    public function testBorraSoloLasReservasSimuladas(): void
    {
        $cliente = $this->huesped();
        $simulada = $this->reservar($cliente, 20);
        $this->pagarSimulado($cliente, $simulada, 'a1');
        $cancelada = $this->reservar($cliente, 30);
        $this->pagarSimulado($cliente, $cancelada, 'a2');
        $this->assertSame(200, $cliente->post('/reservas/cancelar', ['reserva_id' => $cancelada])->codigo);//con su devolución simulada
        $pendiente = $this->reservar($cliente, 40);
        $real = $this->reservar($cliente, 50);
        $this->pagarEnLaRedReal($real);

        [$codigo, $salida] = $this->correrScript(false);
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString("#$simulada confirmada", $salida);
        $this->assertStringContainsString("#$cancelada cancelada", $salida);
        $this->assertStringNotContainsString("#$pendiente ", $salida);
        $this->assertStringNotContainsString("#$real ", $salida);
        $this->assertStringContainsString('No se borró nada', $salida);
        $this->assertSame(1, $this->filas('reservas', $simulada));

        [$codigo, $salida] = $this->correrScript(true);
        $this->assertSame(0, $codigo, $salida);
        //la base de prueba es compartida: también se borran las simuladas que dejaron otros tests, así que no se cuenta el total
        $this->assertMatchesRegularExpression('/Se borraron \d+ reserva\(s\)/', $salida);
        foreach ([$simulada, $cancelada] as $id) {
            $this->assertSame([0, 0], [$this->filas('reservas', $id), $this->filas('pagos', $id)]);
        }
        $this->assertSame([1, 1, 1], [$this->filas('reservas', $pendiente), $this->filas('reservas', $real), $this->filas('pagos', $real)]);

        $this->assertStringContainsString('No hay reservas simuladas.', $this->correrScript(false)[1]);
    }
}
