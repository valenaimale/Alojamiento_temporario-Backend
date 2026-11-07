<?php

namespace App\Validacion;

use App\Config\Config;
use App\Database\Conexion;

//El firmador real: firma con la billetera de la plataforma (PLATAFORMA_CLAVE_PRIVADA, la owner del contrato) y manda
//devolver y liquidar al contrato PasarelaPago en Base Sepolia. Se elige con PAGOS_FIRMADOR=blockchain.
//Usa BLOCKCHAIN_RPC_URL y CONTRATO_DIRECCION, igual que VerificadorBlockchain.
//
//Cómo manda una transacción:
//  1. controla que el RPC sea de la red configurada (84532): la firma solo vale en esa red;
//  2. toma un candado de MySQL: si dos cancelaciones mandan a la vez, no usan el mismo nonce;
//  3. estima el gas con eth_estimateGas. Si el contrato la rechazaría (por ejemplo, FondosInsuficientes o
//     ReservaDevuelta), falla acá, sin gastar gas, y la devolución queda pendiente para reintentar;
//  4. la firma (FirmaEthereum), la manda y espera unos segundos a que entre en un bloque (Base hace uno cada 2 s).
//     Si no entra en ese tiempo, devuelve el envío sin confirmar: lo confirma después scripts/reintentar-devoluciones.php.
class FirmadorBlockchain implements FirmadorPagos
{
    private const SELECTOR_DEVOLVER = 'fd7f3319';//devolver(uint256,address,uint256)
    private const SELECTOR_LIQUIDAR = 'fc500b5f';//liquidar(uint256,address[],uint256[])
    private const CANDADO = 'firmador_plataforma';
    private const ESPERAS_DE_CONFIRMACION = 5;//de a 2 segundos

    private ClienteRpc $rpc;
    private string $contrato;
    private ?FirmaEthereum $firma = null;
    private string $clavePrivada;

    public function __construct(?ClienteRpc $rpc = null, ?string $contrato = null, ?string $clavePrivada = null)
    {
        $this->rpc = $rpc ?? new ClienteRpc(null, 15);
        $this->contrato = strtolower($contrato ?? (string) Config::obtener('contrato.direccion', ''));
        if (!ClienteRpc::esDireccion($this->contrato)) {
            throw new \RuntimeException('Falta configurar CONTRATO_DIRECCION (la dirección del contrato PasarelaPago)');
        }
        $this->clavePrivada = $clavePrivada ?? (string) Config::obtener('plataforma.clave_privada', '');
        if ($this->clavePrivada === '') {
            throw new \RuntimeException('Falta configurar PLATAFORMA_CLAVE_PRIVADA (la billetera de la plataforma)');
        }
    }

    public function red(): int
    {
        return (int) Config::obtener('blockchain.red', VerificadorBlockchain::RED_BASE_SEPOLIA);
    }

    public function devolver(int $reservaId, string $billetera, string $montoWei): EnvioTransaccion
    {
        if (!ClienteRpc::esDireccion($billetera)) {
            throw new \RuntimeException("La billetera del huésped no es válida: $billetera");
        }
        $datos = '0x' . self::SELECTOR_DEVOLVER . ClienteRpc::palabraUint((string) $reservaId)
            . ClienteRpc::palabraDireccion($billetera) . ClienteRpc::palabraUint($montoWei);
        return $this->mandar($datos);
    }

    public function liquidar(int $reservaId, array $destinatarios, array $montosWei): EnvioTransaccion
    {
        $destinatarios = array_values($destinatarios);
        $montosWei = array_values($montosWei);
        if ($destinatarios === [] || count($destinatarios) !== count($montosWei)) {
            throw new \RuntimeException('La liquidación necesita un monto por destinatario');
        }
        foreach ($destinatarios as $destinatario) {
            if (!ClienteRpc::esDireccion($destinatario)) {
                throw new \RuntimeException("Billetera de destino no válida: $destinatario");
            }
        }
        //ABI: (uint256, address[], uint256[]). Los dos arreglos son dinámicos: en la cabecera va dónde empieza cada uno
        $n = count($destinatarios);
        $inicioDestinatarios = 3 * 32;
        $inicioMontos = $inicioDestinatarios + 32 * (1 + $n);
        $datos = '0x' . self::SELECTOR_LIQUIDAR . ClienteRpc::palabraUint((string) $reservaId)
            . ClienteRpc::palabraUint((string) $inicioDestinatarios) . ClienteRpc::palabraUint((string) $inicioMontos)
            . ClienteRpc::palabraUint((string) $n) . implode('', array_map([ClienteRpc::class, 'palabraDireccion'], $destinatarios))
            . ClienteRpc::palabraUint((string) $n) . implode('', array_map(fn ($m) => ClienteRpc::palabraUint((string) $m), $montosWei));
        return $this->mandar($datos);
    }

    public function estado(string $txHash): ?bool
    {
        $recibo = $this->rpc->llamar('eth_getTransactionReceipt', [$txHash]);
        if ($recibo !== null && !empty($recibo['blockNumber'])) {
            return ($recibo['status'] ?? '0x0') === '0x1';
        }
        //sin recibo: si la red ni la conoce, se perdió (por ejemplo, la reemplazó otra con el mismo nonce)
        return $this->rpc->llamar('eth_getTransactionByHash', [$txHash]) === null ? false : null;
    }

    //Firma y manda una llamada al contrato, sin ETH. Devuelve el hash y si ya está confirmada.
    private function mandar(string $datos): EnvioTransaccion
    {
        $chainId = (int) ClienteRpc::hexADecimal((string) $this->rpc->llamar('eth_chainId'));
        if ($chainId !== $this->red()) {
            throw new \RuntimeException("El RPC es de la red $chainId y la plataforma firma para la {$this->red()}");
        }
        $this->firma ??= new FirmaEthereum($this->clavePrivada);
        $desde = $this->firma->direccion();

        $pdo = Conexion::obtener();
        if ((int) $pdo->query("SELECT GET_LOCK('" . self::CANDADO . "', 20)")->fetchColumn() !== 1) {
            throw new \RuntimeException('Hay otra transacción de la plataforma saliendo: se reintenta después');
        }
        try {
            $llamada = ['from' => $desde, 'to' => $this->contrato, 'data' => $datos];
            $gas = ClienteRpc::hexADecimal((string) $this->rpc->llamar('eth_estimateGas', [$llamada]));
            $gas = bcdiv(bcmul($gas, '130', 0), '100', 0);//30 % de margen
            $gasPrice = ClienteRpc::hexADecimal((string) $this->rpc->llamar('eth_gasPrice'));
            $gasPrice = bcdiv(bcmul($gasPrice, '125', 0), '100', 0);//25 % más, para que no quede atrás si sube
            $nonce = ClienteRpc::hexADecimal((string) $this->rpc->llamar('eth_getTransactionCount', [$desde, 'pending']));

            $firmada = $this->firma->firmar($nonce, $gasPrice, $gas, $this->contrato, '0', $datos, $chainId);
            $txHash = strtolower((string) $this->rpc->llamar('eth_sendRawTransaction', [$firmada]));
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::CANDADO . "')");
        }

        for ($i = 0; $i < self::ESPERAS_DE_CONFIRMACION; $i++) {
            sleep(2);
            try {
                $estado = $this->estado($txHash);
            } catch (\Throwable) {
                $estado = null;//no se pudo preguntar: ya salió, se confirma después
            }
            if ($estado === true) {
                return new EnvioTransaccion($txHash, true);
            }
            if ($estado === false) {
                break;//revirtió: queda sin confirmar y el script la vuelve a mandar
            }
        }
        return new EnvioTransaccion($txHash, false);
    }
}
