<?php

namespace Tests\Unitarios;

use App\Validacion\ClienteRpc;
use App\Validacion\ResultadoVerificacion;
use App\Validacion\VerificadorBlockchain;
use PHPUnit\Framework\TestCase;

//El verificador real (VerificadorBlockchain) contra un RPC de mentira que devuelve recibos armados a mano,
//con el formato del evento PagoRealizado del contrato (README de Alojamiento_temporario-Contratos).
final class VerificadorBlockchainTest extends TestCase
{
    private const CONTRATO = '0xf9d86d0e7c3d2520103fd11e64174c9e6bcfc0f4';
    private const PAGADOR = '0x00000000000000000000000000000000000abcde';
    private const HASH = '0xab00000000000000000000000000000000000000000000000000000000000001';

    //Un RPC que responde lo que se le diga, sin red
    private function rpc(?array $recibo, mixed $transaccion = null): ClienteRpc
    {
        return new class ($recibo, $transaccion) extends ClienteRpc {
            public function __construct(private ?array $recibo, private mixed $transaccion)
            {
                parent::__construct('http://rpc.invalido');
            }

            public function llamar(string $metodo, array $parametros = []): mixed
            {
                return match ($metodo) {
                    'eth_getTransactionReceipt' => $this->recibo,
                    'eth_getTransactionByHash'  => $this->transaccion,
                };
            }
        };
    }

    //Un recibo con un log PagoRealizado(reservaId, PAGADOR, monto, tipo)
    private function recibo(int $reservaId, string $montoWei, int $tipo, string $emisor = self::CONTRATO, string $estado = '0x1'): array
    {
        return [
            'blockNumber' => '0x10', 'status' => $estado, 'from' => self::PAGADOR,
            'logs' => [[
                'address' => $emisor,
                'topics'  => [
                    VerificadorBlockchain::TOPIC_PAGO_REALIZADO,
                    '0x' . ClienteRpc::palabraUint((string) $reservaId),
                    '0x' . ClienteRpc::palabraDireccion(self::PAGADOR),
                ],
                'data' => '0x' . ClienteRpc::palabraUint($montoWei) . ClienteRpc::palabraUint((string) $tipo),
            ]],
        ];
    }

    private function verificar(?array $recibo, string $tipo = 'sena', string $montoWei = '420000000000000', mixed $transaccion = null): ResultadoVerificacion
    {
        return (new VerificadorBlockchain($this->rpc($recibo, $transaccion), self::CONTRATO))->verificar(self::HASH, 12, $tipo, $montoWei);
    }

    public function testConfirmaElPagoDeLaReserva(): void
    {
        $resultado = $this->verificar($this->recibo(12, '420000000000000', 0));
        $this->assertSame([ResultadoVerificacion::CONFIRMADO, self::PAGADOR, '420000000000000'], [$resultado->estado, $resultado->billetera, $resultado->montoWei]);

        $saldo = $this->verificar($this->recibo(12, '1680000000000000', 1), 'saldo', '1680000000000000');
        $this->assertSame(ResultadoVerificacion::CONFIRMADO, $saldo->estado);
    }

    public function testSiPagaDeMasGuardaLoQuePago(): void
    {
        $resultado = $this->verificar($this->recibo(12, '420000000000001', 0));
        $this->assertSame([ResultadoVerificacion::CONFIRMADO, '420000000000001'], [$resultado->estado, $resultado->montoWei]);
    }

    public function testSinBloqueTodaviaEstaPendiente(): void
    {
        $this->assertSame(ResultadoVerificacion::PENDIENTE, $this->verificar(null)->estado);
        $this->assertSame(ResultadoVerificacion::PENDIENTE, $this->verificar(null, transaccion: ['hash' => self::HASH])->estado);
    }

    public function testLoQueNoEsUnPagoValidoFalla(): void
    {
        $casos = [
            'revirtió'      => [$this->recibo(12, '420000000000000', 0, estado: '0x0'), 'La transacción falló en la red'],
            'otro contrato' => [$this->recibo(12, '420000000000000', 0, emisor: '0x' . str_repeat('1', 40)), 'La transacción no es un pago de esta reserva en el contrato de HayLugar'],
            'otra reserva'  => [$this->recibo(13, '420000000000000', 0), 'La transacción no es un pago de esta reserva en el contrato de HayLugar'],
            'pagó el saldo' => [$this->recibo(12, '420000000000000', 1), 'La transacción pagó el saldo, no la seña'],
            'pagó de menos' => [$this->recibo(12, '419999999999999', 0), 'El monto pagado no alcanza para cubrir el importe'],
        ];
        foreach ($casos as $caso => [$recibo, $motivo]) {
            $resultado = $this->verificar($recibo);
            $this->assertSame([ResultadoVerificacion::FALLIDO, $motivo, self::PAGADOR], [$resultado->estado, $resultado->motivo, $resultado->billetera], $caso);
        }
    }

    public function testConvierteNumerosGrandes(): void
    {
        $this->assertSame('1680000000000000000000', ClienteRpc::hexADecimal('0x' . ClienteRpc::decimalAHex('1680000000000000000000')));
        $this->assertSame('0', ClienteRpc::hexADecimal('0x0'));
        $this->assertSame(str_repeat('0', 63) . 'c', ClienteRpc::palabraUint('12'));
    }

    public function testDaLosDatosParaQueElFrontPague(): void
    {
        $verificador = new VerificadorBlockchain($this->rpc(null), self::CONTRATO);
        $this->assertSame(['contrato' => self::CONTRATO, 'funcion' => 'pagarSaldo', 'simulado' => false], $verificador->datosParaPagar(12, 'saldo', '1'));
        $this->assertSame(84532, $verificador->red());
    }
}
