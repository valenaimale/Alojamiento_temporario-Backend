<?php

namespace App\Validacion;

use App\Config\Config;

//El verificador real: busca la transacción en Base Sepolia (eth_getTransactionReceipt) y controla que tenga el evento
//PagoRealizado(uint256 indexed reservaId, address indexed pagador, uint256 monto, uint8 tipo) del contrato PasarelaPago
//(README del repo Alojamiento_temporario-Contratos, sección "Para el back").
//Se elige con PAGOS_VERIFICADOR=blockchain. Usa BLOCKCHAIN_RPC_URL y CONTRATO_DIRECCION.
//
//Qué controla:
//  - que la transacción esté en un bloque (si no, pendiente: el front vuelve a preguntar) y que no haya revertido;
//  - que el log lo haya emitido LA dirección del contrato (cualquiera puede desplegar otro con un evento igual);
//  - que sea de esa reserva (topics[1]) y de ese tipo (0 = seña, 1 = saldo);
//  - que el monto pagado alcance. Si paga de más, se acepta y se guarda lo que pagó de verdad (las devoluciones
//    se calculan sobre el wei que entró, decisión #32).
class VerificadorBlockchain implements VerificadorPagos
{
    public const RED_BASE_SEPOLIA = 84532;
    public const TOPIC_PAGO_REALIZADO = '0x26595ff0dee9598a8c85c81c2b2cac01dcd205cfe43ba94cf10fde1e4598a161';
    private const TIPOS = ['sena' => 0, 'saldo' => 1];

    private ClienteRpc $rpc;
    private string $contrato;

    public function __construct(?ClienteRpc $rpc = null, ?string $contrato = null)
    {
        $this->rpc = $rpc ?? new ClienteRpc();
        $this->contrato = strtolower($contrato ?? (string) Config::obtener('contrato.direccion', ''));
        if (!ClienteRpc::esDireccion($this->contrato)) {
            throw new \RuntimeException('Falta configurar CONTRATO_DIRECCION (la dirección del contrato PasarelaPago)');
        }
    }

    public function red(): int
    {
        return (int) Config::obtener('blockchain.red', self::RED_BASE_SEPOLIA);
    }

    public function datosParaPagar(int $reservaId, string $tipo, string $montoWei): array
    {
        return ['contrato' => $this->contrato, 'funcion' => $tipo === 'saldo' ? 'pagarSaldo' : 'pagarSena', 'simulado' => false];
    }

    public function verificar(string $txHash, int $reservaId, string $tipo, string $montoWei): ResultadoVerificacion
    {
        $recibo = $this->rpc->llamar('eth_getTransactionReceipt', [$txHash]);
        if ($recibo === null || empty($recibo['blockNumber'])) {
            //todavía no está en un bloque, o el nodo todavía no la vio (MetaMask la manda por su propio RPC):
            //no se guarda nada y el front vuelve a preguntar (decisión #4)
            return ResultadoVerificacion::pendiente();
        }
        $remitente = isset($recibo['from']) && ClienteRpc::esDireccion($recibo['from']) ? strtolower($recibo['from']) : null;

        if (($recibo['status'] ?? '0x0') !== '0x1') {
            return ResultadoVerificacion::fallido('La transacción falló en la red', $remitente);
        }

        $reservaTopic = '0x' . ClienteRpc::palabraUint((string) $reservaId);
        $encontrado = false;
        foreach ($recibo['logs'] ?? [] as $log) {
            $topics = array_map('strtolower', $log['topics'] ?? []);
            if (strtolower($log['address'] ?? '') !== $this->contrato || count($topics) < 3
                || $topics[0] !== self::TOPIC_PAGO_REALIZADO || $topics[1] !== $reservaTopic) {
                continue;
            }
            $datos = substr((string) ($log['data'] ?? ''), 2);
            if (strlen($datos) < 128) {
                continue;
            }
            $encontrado = true;
            $pagador = '0x' . substr($topics[2], 26);
            $monto = ClienteRpc::hexADecimal(substr($datos, 0, 64));
            $tipoLog = (int) ClienteRpc::hexADecimal(substr($datos, 64, 64));
            if ($tipoLog !== self::TIPOS[$tipo]) {
                return ResultadoVerificacion::fallido(
                    $tipo === 'sena' ? 'La transacción pagó el saldo, no la seña' : 'La transacción pagó la seña, no el saldo',
                    $pagador
                );
            }
            if (bccomp($monto, $montoWei, 0) < 0) {
                return ResultadoVerificacion::fallido('El monto pagado no alcanza para cubrir el importe', $pagador);
            }
            return ResultadoVerificacion::confirmado($pagador, $monto);
        }
        return ResultadoVerificacion::fallido(
            $encontrado ? 'La transacción no corresponde a este pago' : 'La transacción no es un pago de esta reserva en el contrato de HayLugar',
            $remitente
        );
    }
}
