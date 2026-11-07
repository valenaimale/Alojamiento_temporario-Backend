<?php

namespace Tests\Unitarios;

use App\Validacion\FirmaEthereum;
use PHPUnit\Framework\TestCase;

//La firma de transacciones de la plataforma (FirmaEthereum), con el ejemplo oficial de EIP-155:
//https://eips.ethereum.org/EIPS/eip-155 (clave 0x4646…46, nonce 9, 20 gwei, 21000 de gas, 1 ETH a 0x3535…35, red 1).
//Necesita la extensión gmp (la usa la librería de curvas elípticas); sin ella, se saltea.
final class FirmaEthereumTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gmp')) {
            $this->markTestSkipped('Falta la extensión gmp de PHP');
        }
    }

    public function testFirmaComoElEjemploDeEip155(): void
    {
        $firma = new FirmaEthereum('0x' . str_repeat('46', 32));

        $this->assertSame('0x9d8a62f656a8d1615c1294fd71e9cfb3e4855a4f', $firma->direccion());
        $this->assertSame(
            '0xf86c098504a817c800825208943535353535353535353535353535353535353535880de0b6b3a76400008025a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e1590620aa636276a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83',
            $firma->firmar('9', '20000000000', '21000', '0x' . str_repeat('35', 20), '1000000000000000000', '0x', 1)
        );
    }

    public function testRechazaUnaClaveInvalida(): void
    {
        $this->expectException(\RuntimeException::class);
        new FirmaEthereum('0x1234');
    }
}
