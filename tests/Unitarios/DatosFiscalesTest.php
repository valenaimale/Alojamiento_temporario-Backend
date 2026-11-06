<?php

namespace Tests\Unitarios;

use App\Validacion\DatosFiscales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//Validación de los datos fiscales y bancarios de propietarios y administradores (DER v2, punto 19).
//Solo DatosFiscales::validar(): no usa la base, así que se prueba sin servidor.
final class DatosFiscalesTest extends TestCase
{
    private const CBU = '2850590940090418135201';
    private const BILLETERA = '0x734779432891a4e2c9152fdf6ecffc34825b36b4';

    //Datos válidos de alguien que no cobra IVA; $cambios reemplaza campos puntuales
    private static function sinIva(array $cambios = []): array
    {
        return array_merge(['cobra_iva' => '0', 'cbu' => self::CBU, 'alias' => '', 'titular_cuenta' => 'Marisa Gómez', 'billetera' => self::BILLETERA], $cambios);
    }

    //Datos válidos de alguien que cobra IVA
    private static function conIva(array $cambios = []): array
    {
        return self::sinIva(array_merge([
            'cobra_iva'        => '1',
            'cuit'             => '20-30123456-3',
            'razon_social'     => 'Cabañas del Mar SRL',
            'domicilio_fiscal' => 'Costanera 123, Las Grutas',
        ], $cambios));
    }

    //Sin IVA, los datos fiscales quedan en null, y el alias vacío también
    public function testSinIvaNoPideDatosFiscalesYDejaElAliasEnNull(): void
    {
        $this->assertSame([
            'cobra_iva'        => 0,
            'cuit'             => null,
            'razon_social'     => null,
            'domicilio_fiscal' => null,
            'cbu'              => self::CBU,
            'alias'            => null,
            'titular_cuenta'   => 'Marisa Gómez',
            'billetera'        => self::BILLETERA,
        ], DatosFiscales::validar(self::sinIva()));
    }

    //La billetera con mayúsculas (como la copia MetaMask) y espacios se guarda en minúsculas y sin espacios
    public function testNormalizaLaBilletera(): void
    {
        $datos = DatosFiscales::validar(self::sinIva(['billetera' => ' 0x734779432891a4E2C9152FdF6eCFFC34825b36B4 ']));

        $this->assertSame(self::BILLETERA, $datos['billetera']);
    }

    //Con IVA, el CUIT y el CBU quedan solo con números y el alias en minúsculas
    public function testConIvaNormalizaElCuitElCbuYElAlias(): void
    {
        $datos = DatosFiscales::validar(self::conIva(['cbu' => '2850590 940090418135201', 'alias' => ' Casa.Playa.Sol ']));

        $this->assertSame(1, $datos['cobra_iva']);
        $this->assertSame('20301234563', $datos['cuit']);
        $this->assertSame(self::CBU, $datos['cbu']);
        $this->assertSame('casa.playa.sol', $datos['alias']);
    }

    //Cada dato inválido devuelve su mensaje de error
    #[DataProvider('datosInvalidos')]
    public function testRechazaDatosInvalidos(array $datos, string $error): void
    {
        $this->assertSame(['error' => $error], DatosFiscales::validar($datos));
    }

    //Cada caso: los datos y el error que tienen que dar
    public static function datosInvalidos(): array
    {
        return [
            'no dice si cobra IVA'         => [self::sinIva(['cobra_iva' => '']), 'Indicá si cobrás IVA'],
            'cobra IVA con otro valor'     => [self::sinIva(['cobra_iva' => 'si']), 'Indicá si cobrás IVA'],
            'con IVA y CUIT inválido'      => [self::conIva(['cuit' => '20301234569']), 'El CUIT no es válido'],
            'con IVA y sin CUIT'           => [self::conIva(['cuit' => '']), 'El CUIT no es válido'],
            'con IVA y razón social corta' => [self::conIva(['razon_social' => 'X']), 'La razón social debe tener entre 2 y 150 caracteres'],
            'con IVA y domicilio corto'    => [self::conIva(['domicilio_fiscal' => 'Ca']), 'El domicilio fiscal debe tener entre 5 y 200 caracteres'],
            'sin CBU'                      => [self::sinIva(['cbu' => '']), 'El CBU tiene que tener 22 números'],
            'CBU de 21 números'            => [self::sinIva(['cbu' => '285059094009041813520']), 'El CBU tiene que tener 22 números'],
            'CBU con verificador mal'      => [self::sinIva(['cbu' => '2850590940090418135202']), 'El CBU no es válido: revisá que esté bien escrito'],
            'alias corto'                  => [self::sinIva(['alias' => 'casa']), 'El alias debe tener entre 6 y 20 caracteres: letras sin tilde, números, puntos o guiones'],
            'alias con ñ'                  => [self::sinIva(['alias' => 'montaña.sol']), 'El alias debe tener entre 6 y 20 caracteres: letras sin tilde, números, puntos o guiones'],
            'sin titular'                  => [self::sinIva(['titular_cuenta' => '  ']), 'El titular de la cuenta debe tener entre 2 y 150 caracteres'],
            'sin billetera'                => [self::sinIva(['billetera' => '']), 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
            'billetera sin 0x'             => [self::sinIva(['billetera' => '734779432891a4e2c9152fdf6ecffc34825b36b4']), 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
            'billetera corta'              => [self::sinIva(['billetera' => '0x734779432891a4e2c9152fdf6ecffc34825b36']), 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
            'billetera con letras no hex'  => [self::sinIva(['billetera' => '0x734779432891a4e2c9152fdf6ecffc34825b36zz']), 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
            'billetera 0x000…000'          => [self::sinIva(['billetera' => '0x' . str_repeat('0', 40)]), 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
            'billetera que no es texto'    => [self::sinIva(['billetera' => ['0x1']]), 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'],
        ];
    }
}
