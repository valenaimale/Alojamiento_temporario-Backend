<?php

namespace App\Validacion;

use App\Config\Config;

//Habla con un nodo de Ethereum por JSON-RPC (BLOCKCHAIN_RPC_URL; por defecto el RPC público de Base Sepolia).
//Lo usan el verificador y el firmador reales (VerificadorBlockchain, FirmadorBlockchain).
//Timeouts cortos: el verificador consulta la red con la reserva trabada (FOR UPDATE), decisión #8.
//También tiene las conversiones de números que pide la red: hexadecimal (0x…) ↔ decimal en texto, con bcmath,
//porque los montos en wei no entran en un int de PHP.
class ClienteRpc
{
    public const RPC_POR_DEFECTO = 'https://sepolia.base.org';

    //Selectores de los errores de PasarelaPago que puede devolver un revert (keccak256 de la firma, 4 bytes)
    private const ERRORES_DEL_CONTRATO = [
        '0xa94aa3d1' => 'SinSena',
        '0x6d2120e2' => 'FondosInsuficientes',
        '0xd7d5ad89' => 'ReservaDevuelta',
        '0x5a0504d3' => 'HuespedIncorrecto',
        '0xd753f741' => 'MontoInvalido',
        '0xc66cee38' => 'TransferenciaFallida',
        '0x49a816fc' => 'ListasInvalidas',
        '0x07b07215' => 'DestinatarioInvalido',
        '0x118cdaa7' => 'OwnableUnauthorizedAccount',
    ];

    private int $id = 0;

    public function __construct(private ?string $url = null, private int $timeoutSegundos = 8)
    {
        $this->url ??= (string) Config::obtener('blockchain.rpc_url', self::RPC_POR_DEFECTO);
    }

    //Llama a un método (eth_getTransactionReceipt, eth_sendRawTransaction, ...) y devuelve su "result".
    //Lanza una excepción si no se pudo hablar con el nodo o si el nodo respondió un error (por ejemplo, un revert).
    public function llamar(string $metodo, array $parametros = []): mixed
    {
        $cuerpo = json_encode(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $metodo, 'params' => $parametros]);
        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $this->timeoutSegundos,
        ]);
        $respuesta = curl_exec($curl);
        $codigo = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($curl);
        curl_close($curl);

        if ($respuesta === false) {
            throw new \RuntimeException("No se pudo consultar la red ($metodo): $errorCurl");
        }
        $datos = json_decode((string) $respuesta, true);
        if (!is_array($datos)) {
            throw new \RuntimeException("La red respondió algo que no es JSON ($metodo, HTTP $codigo)");
        }
        if (isset($datos['error'])) {
            $mensaje = is_array($datos['error']) ? ($datos['error']['message'] ?? json_encode($datos['error'])) : (string) $datos['error'];
            $revert = is_array($datos['error']) && is_string($datos['error']['data'] ?? null) ? $datos['error']['data'] : '';
            if ($revert !== '') {//el error del contrato, por nombre si es uno conocido (para el log)
                $mensaje .= ' (' . (self::ERRORES_DEL_CONTRATO[substr($revert, 0, 10)] ?? 'error') . " $revert)";
            }
            throw new \RuntimeException("La red rechazó $metodo: $mensaje");
        }
        if (!array_key_exists('result', $datos)) {
            throw new \RuntimeException("La red no devolvió un resultado ($metodo, HTTP $codigo)");
        }
        return $datos['result'];
    }

    //"0x1a" → "26"
    public static function hexADecimal(string $hex): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', $hex));
        $decimal = '0';
        foreach (str_split($hex === '' ? '0' : $hex) as $digito) {
            $decimal = bcadd(bcmul($decimal, '16', 0), (string) hexdec($digito), 0);
        }
        return $decimal;
    }

    //"26" → "1a" (sin 0x ni ceros a la izquierda; "0" → "0")
    public static function decimalAHex(string $decimal): string
    {
        if (bccomp($decimal, '0', 0) === 0) {
            return '0';
        }
        $hex = '';
        while (bccomp($decimal, '0', 0) > 0) {
            $hex = dechex((int) bcmod($decimal, '16')) . $hex;
            $decimal = bcdiv($decimal, '16', 0);
        }
        return $hex;
    }

    //Un uint256 como palabra ABI de 32 bytes (64 caracteres hexadecimales, sin 0x)
    public static function palabraUint(string $decimal): string
    {
        return str_pad(self::decimalAHex($decimal), 64, '0', STR_PAD_LEFT);
    }

    //Una dirección como palabra ABI de 32 bytes
    public static function palabraDireccion(string $direccion): string
    {
        return str_pad(strtolower(substr($direccion, 2)), 64, '0', STR_PAD_LEFT);
    }

    //Si es una dirección válida: 0x + 40 hexadecimales
    public static function esDireccion(?string $direccion): bool
    {
        return is_string($direccion) && preg_match('/^0x[0-9a-fA-F]{40}$/', $direccion) === 1;
    }
}
