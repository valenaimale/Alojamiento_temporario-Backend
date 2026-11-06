<?php

namespace Tests\Integracion;

use Tests\Soporte\Datos;

//"Hacerme propietario" (un huésped pasa a propietario) y sus datos fiscales y bancarios.
final class PropietarioTest extends CasoDeIntegracion
{
    //Un huésped se hace propietario sin IVA, con su cuenta bancaria, y la sesión pasa a tener el rol nuevo
    public function testUnHuespedSeHacePropietarioSinIva(): void
    {
        [$cliente] = $this->registrar();

        $cuenta = Datos::cuentaBancaria();

        $respuesta = $cliente->post('/hacerse-propietario', array_merge(['cobra_iva' => '0'], $cuenta));

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('propietario', $respuesta->json()['usuario']['rol']);
        $this->assertSame('propietario', $cliente->get('/sesion')->json()['usuario']['rol']);
        $datosFiscales = $cliente->get('/datos-fiscales')->json()['datos_fiscales'];
        $this->assertFalse($datosFiscales['cobra_iva']);
        $this->assertSame($cuenta['cbu'], $datosFiscales['cbu']);
        $this->assertSame($cuenta['titular_cuenta'], $datosFiscales['titular_cuenta']);
        $this->assertSame($cuenta['billetera'], $datosFiscales['billetera']);
    }

    //Con IVA, el CUIT con guiones se guarda solo con números
    public function testUnHuespedSeHacePropietarioConIva(): void
    {
        [$cliente] = $this->registrar();
        $fiscales = Datos::fiscalesConIva();
        $cuitConGuiones = substr($fiscales['cuit'], 0, 2) . '-' . substr($fiscales['cuit'], 2, 8) . '-' . substr($fiscales['cuit'], 10);

        $respuesta = $cliente->post('/hacerse-propietario', array_merge($fiscales, Datos::cuentaBancaria(), ['cuit' => $cuitConGuiones]));

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($fiscales['cuit'], $cliente->get('/datos-fiscales')->json()['datos_fiscales']['cuit']);
    }

    //Un propietario que vuelve a pedirlo recibe 409
    public function testUnPropietarioNoPuedeVolverAHacerse(): void
    {
        [$cliente] = $this->registrar('propietario');

        $respuesta = $cliente->post('/hacerse-propietario', ['cobra_iva' => '0']);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Ya sos propietario', $respuesta->json()['error']);
    }

    //No se puede usar el CUIT de otro propietario
    public function testNoSePuedeRepetirElCuit(): void
    {
        $fiscales = Datos::fiscalesConIva();
        $this->registrar('propietario', $fiscales);
        [$cliente] = $this->registrar();

        $respuesta = $cliente->post('/hacerse-propietario', array_merge($fiscales, Datos::cuentaBancaria()));

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El CUIT ya está registrado', $respuesta->json()['error']);
    }

    //Cada dato fiscal o bancario inválido se rechaza con su mensaje, y el usuario sigue siendo huésped
    public function testDatosFiscalesInvalidos(): void
    {
        [$cliente] = $this->registrar();
        $casos = [
            'Indicá si cobrás IVA'                                   => ['cobra_iva' => 'si'],
            'El CUIT no es válido'                                   => array_merge(Datos::fiscalesConIva(), ['cuit' => '20301234569']),
            'La razón social debe tener entre 2 y 150 caracteres'    => array_merge(Datos::fiscalesConIva(), ['razon_social' => 'X']),
            'El domicilio fiscal debe tener entre 5 y 200 caracteres' => array_merge(Datos::fiscalesConIva(), ['domicilio_fiscal' => 'Ca']),
            'El CBU tiene que tener 22 números'                      => ['cobra_iva' => '0'],
            'El titular de la cuenta debe tener entre 2 y 150 caracteres' => array_merge(['cobra_iva' => '0'], Datos::cuentaBancaria(), ['titular_cuenta' => '']),
            'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)' => array_merge(['cobra_iva' => '0'], Datos::cuentaBancaria(), ['billetera' => '']),
        ];

        foreach ($casos as $error => $datos) {
            $respuesta = $cliente->post('/hacerse-propietario', $datos);
            $this->assertSame(422, $respuesta->codigo, $error);
            $this->assertSame($error, $respuesta->json()['error']);
        }
        //ninguno de los intentos lo convirtió en propietario
        $this->assertSame('huesped', $cliente->get('/sesion')->json()['usuario']['rol']);
    }

    //Un propietario de antes de la billetera (sin billetera en la base) la carga desde el perfil
    public function testUnPropietarioSinBilleteraLaCarga(): void
    {
        [$cliente, , $usuario] = $this->registrar('propietario');
        $this->pdo()->prepare('UPDATE propietarios SET billetera = NULL WHERE usuario_id = ?')->execute([$usuario['id']]);
        $this->assertNull($cliente->get('/datos-fiscales')->json()['datos_fiscales']['billetera']);

        $respuesta = $cliente->post('/datos-fiscales/billetera', ['billetera' => ' 0x734779432891a4E2C9152FdF6eCFFC34825b36B4 ']);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $esperada = '0x734779432891a4e2c9152fdf6ecffc34825b36b4';//en minúsculas y sin espacios
        $this->assertSame($esperada, $respuesta->json()['datos_fiscales']['billetera']);
        $this->assertSame($esperada, $cliente->get('/datos-fiscales')->json()['datos_fiscales']['billetera']);
    }

    //Un administrador cambia su billetera, y volver a mandar la misma también responde 200
    public function testUnAdministradorCambiaSuBilletera(): void
    {
        [$cliente] = $this->registrar('administrador');
        $nueva = Datos::billetera();

        $this->assertSame(200, $cliente->post('/datos-fiscales/billetera', ['billetera' => $nueva])->codigo);
        $this->assertSame(200, $cliente->post('/datos-fiscales/billetera', ['billetera' => $nueva])->codigo);

        $this->assertSame($nueva, $cliente->get('/datos-fiscales')->json()['datos_fiscales']['billetera']);
    }

    //Una billetera inválida se rechaza y la anterior queda como estaba
    public function testUnaBilleteraInvalidaNoSeGuarda(): void
    {
        [$cliente, $datos] = $this->registrar('propietario');

        $respuesta = $cliente->post('/datos-fiscales/billetera', ['billetera' => '0x' . str_repeat('0', 40)]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)', $respuesta->json()['error']);
        $this->assertSame($datos['billetera'], $cliente->get('/datos-fiscales')->json()['datos_fiscales']['billetera']);
    }

    //Un huésped no tiene billetera de cobro: 403
    public function testUnHuespedNoCargaBilleteraDeCobro(): void
    {
        [$cliente] = $this->registrar();

        $respuesta = $cliente->post('/datos-fiscales/billetera', ['billetera' => Datos::billetera()]);

        $this->assertSame(403, $respuesta->codigo);
    }

    //Sin sesión no se puede cargar una billetera
    public function testSinSesionNoSeCargaBilletera(): void
    {
        $respuesta = $this->cliente()->post('/datos-fiscales/billetera', ['billetera' => Datos::billetera()]);

        $this->assertSame(401, $respuesta->codigo);
    }
}
