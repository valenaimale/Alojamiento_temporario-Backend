<?php

namespace App\Validacion;

use Elliptic\EC;
use kornrunner\Keccak;

//Firma transacciones de Ethereum con una clave privada, sin hablar con la red: arma la transacción
//"legacy" con chain id (EIP-155), la codifica en RLP, la firma con secp256k1 y devuelve los bytes listos para
//eth_sendRawTransaction. La usa FirmadorBlockchain con la clave de la plataforma (PLATAFORMA_CLAVE_PRIVADA).
//No toca la base ni la red: se prueba con el ejemplo oficial de EIP-155 (tests/Unitarios/FirmaEthereumTest.php).
//Necesita la extensión gmp de PHP (la usa la librería de curvas elípticas).
class FirmaEthereum
{
    private EC $curva;
    private $clave;

    public function __construct(string $clavePrivada)
    {
        $clavePrivada = strtolower(preg_replace('/^0x/i', '', trim($clavePrivada)));
        if (preg_match('/^[0-9a-f]{64}$/', $clavePrivada) !== 1) {
            throw new \RuntimeException('La clave privada de la plataforma no es válida (tienen que ser 64 hexadecimales)');
        }
        $this->curva = new EC('secp256k1');
        $this->clave = $this->curva->keyFromPrivate($clavePrivada, 'hex');
    }

    //La dirección pública de la clave (0x + 40 hexadecimales, en minúsculas)
    public function direccion(): string
    {
        $publica = substr($this->clave->getPublic(false, 'hex'), 2);//sin el 04 del formato sin comprimir
        return '0x' . substr(Keccak::hash(hex2bin($publica), 256), 24);
    }

    //Firma una transacción. Los números van como texto decimal (wei, gas, nonce); $para y $datos en hexadecimal (0x…).
    //Devuelve la transacción firmada en hexadecimal (0x…).
    public function firmar(string $nonce, string $gasPrice, string $gas, string $para, string $valor, string $datos, int $chainId): string
    {
        $campos = [
            self::entero($nonce), self::entero($gasPrice), self::entero($gas),
            hex2bin(substr($para, 2)), self::entero($valor), hex2bin(substr($datos, 2) ?: ''),
        ];
        //EIP-155: se firma con chain id, 0, 0 al final, para que la firma no sirva en otra red
        $hash = Keccak::hash(self::rlp([...$campos, self::entero((string) $chainId), '', '']), 256);
        $firma = $this->clave->sign($hash, ['canonical' => true]);

        $v = (string) ($firma->recoveryParam + $chainId * 2 + 35);
        $r = self::entero($firma->r->toString(10));
        $s = self::entero($firma->s->toString(10));
        return '0x' . bin2hex(self::rlp([...$campos, self::entero($v), $r, $s]));
    }

    //Un número (texto decimal) como bytes big-endian sin ceros a la izquierda (el 0 es la cadena vacía, como pide RLP)
    private static function entero(string $decimal): string
    {
        if (bccomp($decimal, '0', 0) === 0) {
            return '';
        }
        $hex = ClienteRpc::decimalAHex($decimal);
        return hex2bin(strlen($hex) % 2 === 1 ? "0$hex" : $hex);
    }

    //Codificación RLP de una cadena de bytes o de una lista
    private static function rlp(string|array $item): string
    {
        if (is_array($item)) {
            $cuerpo = implode('', array_map([self::class, 'rlp'], $item));
            return self::prefijo(strlen($cuerpo), 0xc0) . $cuerpo;
        }
        if (strlen($item) === 1 && ord($item) < 0x80) {
            return $item;
        }
        return self::prefijo(strlen($item), 0x80) . $item;
    }

    private static function prefijo(int $largo, int $base): string
    {
        if ($largo < 56) {
            return chr($base + $largo);
        }
        $bytesLargo = ltrim(pack('J', $largo), "\0");
        return chr($base + 55 + strlen($bytesLargo)) . $bytesLargo;
    }
}
