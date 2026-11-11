<?php

namespace Tests\Unitarios;

use App\Validacion\Liquidacion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//La cuenta de una liquidación: IVA, base de comisiones, comisión de la plataforma, comisión del administrador
//e importe del propietario (RN-24, RN-25). Solo Liquidacion::calcular() y Liquidacion::faltante():
//no usan la base, así que se prueban sin servidor.
//Los dos primeros casos son los números del comprobante que armó el contador, con $425.000 cobrados:
//tienen que salir exactos al centavo (RNF-22).
final class LiquidacionTest extends TestCase
{
    private const IVA = '21';
    private const PLATAFORMA = '7.5';

    //El comprobante de una propietaria que discrimina IVA, con administrador al 10 %
    public function testPropietarioQueDiscriminaIvaConAdministrador(): void
    {
        $this->assertSame([
            'importe_cobrado'                   => '425000.00',
            'porcentaje_iva'                    => '21',
            'importe_iva'                       => '73760.33',   //el 21 % incluido en el precio
            'base_comisiones'                   => '351239.67',  //el neto sin IVA
            'porcentaje_comision_plataforma'    => '7.5',
            'comision_plataforma'               => '26342.98',
            'porcentaje_comision_administrador' => '10',
            'comision_administrador'            => '35123.97',
            'importe_propietario'               => '363533.05',
        ], Liquidacion::calcular('425000.00', true, self::IVA, self::PLATAFORMA, '10'));
    }

    //El mismo importe, pero de una monotributista: sin IVA discriminado, las comisiones van sobre el total (RN-24)
    public function testPropietarioQueNoDiscriminaIvaCobraComisionesSobreElTotal(): void
    {
        $this->assertSame([
            'importe_cobrado'                   => '425000.00',
            'porcentaje_iva'                    => '0',   //no discrimina IVA: no hay tasa que mostrar
            'importe_iva'                       => '0.00',
            'base_comisiones'                   => '425000.00',
            'porcentaje_comision_plataforma'    => '7.5',
            'comision_plataforma'               => '31875.00',
            'porcentaje_comision_administrador' => '10',
            'comision_administrador'            => '42500.00',
            'importe_propietario'               => '350625.00',
        ], Liquidacion::calcular('425000.00', false, self::IVA, self::PLATAFORMA, '10'));
    }

    //Unidad de autogestión: no hay administrador, así que no hay comisión que pagarle
    public function testSinAdministradorNoHayComisionDeAdministrador(): void
    {
        $reparto = Liquidacion::calcular('300000.00', false, self::IVA, self::PLATAFORMA, null);

        $this->assertNull($reparto['porcentaje_comision_administrador']);
        $this->assertSame('0.00', $reparto['comision_administrador']);
        $this->assertSame('22500.00', $reparto['comision_plataforma']);
        $this->assertSame('277500.00', $reparto['importe_propietario']);
    }

    //La base más el IVA tienen que dar siempre el total cobrado, sin un centavo de más ni de menos:
    //es lo que la base garantiza con el CHECK chk_liquidaciones_iva
    #[DataProvider('importes')]
    public function testLaBaseMasElIvaDanElTotalCobrado(string $cobrado): void
    {
        $reparto = Liquidacion::calcular($cobrado, true, self::IVA, self::PLATAFORMA, '15');

        $this->assertSame(
            $this->centavos($cobrado),
            $this->centavos($reparto['base_comisiones']) + $this->centavos($reparto['importe_iva'])
        );
    }

    //Y el propietario tiene que recibir exactamente lo cobrado menos las dos comisiones:
    //el CHECK chk_liquidaciones_reparto
    #[DataProvider('importes')]
    public function testElPropietarioRecibeLoCobradoMenosLasComisiones(string $cobrado): void
    {
        $reparto = Liquidacion::calcular($cobrado, true, self::IVA, self::PLATAFORMA, '15');

        $this->assertSame(
            $this->centavos($cobrado),
            $this->centavos($reparto['importe_propietario'])
                + $this->centavos($reparto['comision_plataforma'])
                + $this->centavos($reparto['comision_administrador'])
        );
    }

    //Importes elegidos para que las divisiones no den redondas
    public static function importes(): array
    {
        return [
            'el del contador'   => ['425000.00'],
            'con centavos'      => ['123456.78'],
            'un peso'           => ['1.00'],
            'siete centavos'    => ['0.07'],
            'un centavo'        => ['0.01'],
            'importe redondo'   => ['100000.00'],
            'seis cifras impar' => ['999999.99'],
        ];
    }

    //Un importe chico también se reparte sin perder ni inventar centavos
    public function testUnImporteDeUnPesoSeReparteSinPerderCentavos(): void
    {
        $reparto = Liquidacion::calcular('1.00', true, self::IVA, self::PLATAFORMA, '10');

        $this->assertSame('0.83', $reparto['base_comisiones']);   //1 / 1,21 = 0,8264…
        $this->assertSame('0.17', $reparto['importe_iva']);
        $this->assertSame('0.06', $reparto['comision_plataforma']);     //0,83 × 7,5 % = 0,06225
        $this->assertSame('0.08', $reparto['comision_administrador']);  //0,83 × 10 % = 0,083
        $this->assertSame('0.86', $reparto['importe_propietario']);
    }

    //Nada cobrado: todo queda en cero y no se rompe
    public function testSinNadaCobradoTodoQuedaEnCero(): void
    {
        $reparto = Liquidacion::calcular('0.00', true, self::IVA, self::PLATAFORMA, '10');

        $this->assertSame('0.00', $reparto['importe_cobrado']);
        $this->assertSame('0.00', $reparto['importe_iva']);
        $this->assertSame('0.00', $reparto['base_comisiones']);
        $this->assertSame('0.00', $reparto['comision_plataforma']);
        $this->assertSame('0.00', $reparto['importe_propietario']);
    }

    //Un IVA distinto del 21 % (el parámetro lo administra el backoffice) también se respeta
    public function testTomaElIvaDelParametroYNoUnoFijo(): void
    {
        $reparto = Liquidacion::calcular('110000.00', true, '10', '7.5', null);

        $this->assertSame('10', $reparto['porcentaje_iva']);
        $this->assertSame('100000.00', $reparto['base_comisiones']);
        $this->assertSame('10000.00', $reparto['importe_iva']);
        $this->assertSame('7500.00', $reparto['comision_plataforma']);
    }

    //El faltante es el motivo por el que una liquidación queda retenida por deuda (RN-16)
    public function testElFaltanteEsLoQueQuedaSinCobrar(): void
    {
        $this->assertSame('120000.00', Liquidacion::faltante('400000.00', '280000.00'));
        $this->assertSame('0.01', Liquidacion::faltante('400000.00', '399999.99'));
    }

    //Si está todo cobrado (o se cobró de más), no hay faltante
    public function testSinDeudaNoHayFaltante(): void
    {
        $this->assertNull(Liquidacion::faltante('400000.00', '400000.00'));
        $this->assertNull(Liquidacion::faltante('400000.00', '400000.01'));
    }

    //Una cancelada no debe nada: se liquida lo que se retuvo, aunque sea mucho menos que el total (decisión #57)
    public function testUnaCanceladaNoTieneFaltante(): void
    {
        $this->assertNull(Liquidacion::faltante('400000.00', '24000.00', true));
    }

    //El reparto en wei sigue la proporción de los pesos, y entre todos da exactamente lo disponible (decisión #59)
    public function testRepartirWeiSumaExactamenteLoDisponible(): void
    {
        $wei = Liquidacion::repartirWei('1000000000000001', ['propietario' => '700.00', 'administrador' => '200.00', 'plataforma' => '100.00']);

        $this->assertSame(['propietario' => '700000000000000', 'administrador' => '200000000000000', 'plataforma' => '100000000000001'], $wei);
    }

    //Los restos del redondeo van a la plataforma, nunca de más: cada parte se redondea para abajo
    public function testRepartirWeiRedondeaParaAbajoYElRestoVaALaPlataforma(): void
    {
        $wei = Liquidacion::repartirWei('10', ['propietario' => '1.00', 'administrador' => '1.00', 'plataforma' => '1.00']);

        $this->assertSame(['propietario' => '3', 'administrador' => '3', 'plataforma' => '4'], $wei);
    }

    //Si la plataforma no cobra comisión, el resto va al propietario: así no queda nada trabado en el contrato
    public function testSinComisionDeLaPlataformaElRestoVaAlPropietario(): void
    {
        $wei = Liquidacion::repartirWei('10', ['propietario' => '2.00', 'administrador' => '1.00', 'plataforma' => '0.00']);

        $this->assertSame(['propietario' => '7', 'administrador' => '3', 'plataforma' => '0'], $wei);
    }

    //Sin nada disponible no se reparte nada
    public function testSinNadaDisponibleTodoDaCero(): void
    {
        $wei = Liquidacion::repartirWei('0', ['propietario' => '700.00', 'administrador' => '0.00', 'plataforma' => '100.00']);

        $this->assertSame(['propietario' => '0', 'administrador' => '0', 'plataforma' => '0'], $wei);
    }

    //"425000.00" → 42500000, para comparar importes como enteros en los tests
    private function centavos(string $monto): int
    {
        [$entera, $decimal] = explode('.', $monto);
        return (int) $entera * 100 + (int) $decimal;
    }
}
