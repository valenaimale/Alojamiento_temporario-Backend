<?php

namespace Tests\Integracion;

use Tests\Soporte\Datos;

//"Hacerme propietario" (un huésped pasa a propietario) y sus datos fiscales.
final class PropietarioTest extends CasoDeIntegracion
{
    public function testUnHuespedSeHacePropietarioSinIva(): void
    {
        [$cliente] = $this->registrar();

        $respuesta = $cliente->post('/hacerse-propietario', ['cobra_iva' => '0']);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('propietario', $respuesta->json()['usuario']['rol']);
        $this->assertSame('propietario', $cliente->get('/sesion')->json()['usuario']['rol']);
        $this->assertFalse($cliente->get('/datos-fiscales')->json()['datos_fiscales']['cobra_iva']);
    }

    public function testUnHuespedSeHacePropietarioConIva(): void
    {
        [$cliente] = $this->registrar();
        $fiscales = Datos::fiscalesConIva();
        $cuitConGuiones = substr($fiscales['cuit'], 0, 2) . '-' . substr($fiscales['cuit'], 2, 8) . '-' . substr($fiscales['cuit'], 10);

        $respuesta = $cliente->post('/hacerse-propietario', array_merge($fiscales, ['cuit' => $cuitConGuiones]));

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($fiscales['cuit'], $cliente->get('/datos-fiscales')->json()['datos_fiscales']['cuit']);
    }

    public function testUnPropietarioNoPuedeVolverAHacerse(): void
    {
        [$cliente] = $this->registrar('propietario');

        $respuesta = $cliente->post('/hacerse-propietario', ['cobra_iva' => '0']);

        $this->assertSame(409, $respuesta->codigo);
        $this->assertSame('Ya sos propietario', $respuesta->json()['error']);
    }

    public function testNoSePuedeRepetirElCuit(): void
    {
        $fiscales = Datos::fiscalesConIva();
        $this->registrar('propietario', $fiscales);
        [$cliente] = $this->registrar();

        $respuesta = $cliente->post('/hacerse-propietario', $fiscales);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('El CUIT ya está registrado', $respuesta->json()['error']);
    }

    public function testDatosFiscalesInvalidos(): void
    {
        [$cliente] = $this->registrar();
        $casos = [
            'Indicá si cobrás IVA'                                   => ['cobra_iva' => 'si'],
            'El CUIT no es válido'                                   => array_merge(Datos::fiscalesConIva(), ['cuit' => '20301234569']),
            'La razón social debe tener entre 2 y 150 caracteres'    => array_merge(Datos::fiscalesConIva(), ['razon_social' => 'X']),
            'El domicilio fiscal debe tener entre 5 y 200 caracteres' => array_merge(Datos::fiscalesConIva(), ['domicilio_fiscal' => 'Ca']),
        ];

        foreach ($casos as $error => $datos) {
            $respuesta = $cliente->post('/hacerse-propietario', $datos);
            $this->assertSame(422, $respuesta->codigo, $error);
            $this->assertSame($error, $respuesta->json()['error']);
        }
        //ninguno de los intentos lo convirtió en propietario
        $this->assertSame('huesped', $cliente->get('/sesion')->json()['usuario']['rol']);
    }
}
