<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//Backoffice (PN-11): listado, detalle y alta o baja de cuentas. Solo para el rol backoffice.
final class BackofficeTest extends CasoDeIntegracion
{
    private ClienteApi $backoffice;
    private array $usuarioBackoffice;

    protected function setUp(): void
    {
        $this->usuarioBackoffice = $this->crearUsuarioEnBase('backoffice');
        $this->backoffice = $this->iniciarSesion($this->usuarioBackoffice['mail']);
    }

    public function testListaYBuscaUsuarios(): void
    {
        [, $datos] = $this->registrar();

        $respuesta = $this->backoffice->get('/backoffice/usuarios', ['buscar' => $datos['mail']]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $listado = $respuesta->json();
        $this->assertSame(1, $listado['total']);
        $this->assertSame($datos['mail'], $listado['usuarios'][0]['mail']);
        $this->assertArrayNotHasKey('contrasenia', $listado['usuarios'][0]);
    }

    public function testFiltraPorRol(): void
    {
        $this->registrar('propietario');

        $usuarios = $this->backoffice->get('/backoffice/usuarios', ['rol' => 'propietario'])->json()['usuarios'];

        $this->assertNotEmpty($usuarios);
        $this->assertSame(['propietario'], array_values(array_unique(array_column($usuarios, 'rol'))));
    }

    public function testUnaBusquedaConUnaListaEsRechazada(): void
    {
        $respuesta = $this->backoffice->get('/backoffice/usuarios', ['buscar' => ['a', 'b']]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('Los datos enviados no son válidos', $respuesta->json()['error']);
    }

    public function testDetalleDeUnUsuario(): void
    {
        [, , $usuario] = $this->registrar('propietario');

        $respuesta = $this->backoffice->get('/backoffice/usuario', ['id' => $usuario['id']]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($usuario['mail'], $respuesta->json()['usuario']['mail']);
        $this->assertFalse($respuesta->json()['usuario']['datos_fiscales']['cobra_iva']);
    }

    public function testDetalleDeUnUsuarioInexistente(): void
    {
        $this->assertSame(404, $this->backoffice->get('/backoffice/usuario', ['id' => 999999])->codigo);
        $this->assertSame(422, $this->backoffice->get('/backoffice/usuario', ['id' => 'abc'])->codigo);
    }

    public function testDeshabilitarYReactivarUnaCuenta(): void
    {
        [, $datos, $usuario] = $this->registrar();

        $baja = $this->backoffice->post('/backoffice/usuario/estado', ['id' => $usuario['id'], 'activo' => false]);
        $this->assertSame(200, $baja->codigo, $baja->cuerpo);
        $this->assertSame('Cuenta deshabilitada', $baja->json()['ok']);
        $this->assertFalse($baja->json()['usuario']['activo']);
        $login = $this->cliente()->post('/iniciar-sesion', ['mail' => $datos['mail'], 'contrasenia' => $datos['contrasenia']]);
        $this->assertSame(403, $login->codigo);

        $alta = $this->backoffice->post('/backoffice/usuario/estado', ['id' => $usuario['id'], 'activo' => true]);
        $this->assertSame(200, $alta->codigo);
        $this->assertSame('Cuenta reactivada', $alta->json()['ok']);
        $login = $this->cliente()->post('/iniciar-sesion', ['mail' => $datos['mail'], 'contrasenia' => $datos['contrasenia']]);
        $this->assertSame(200, $login->codigo);
    }

    public function testNoPuedeDeshabilitarSuPropiaCuenta(): void
    {
        $respuesta = $this->backoffice->post('/backoffice/usuario/estado', ['id' => $this->usuarioBackoffice['id'], 'activo' => false]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('No podés deshabilitar tu propia cuenta', $respuesta->json()['error']);
    }

    public function testElEstadoTieneQueSerTrueOFalse(): void
    {
        [, , $usuario] = $this->registrar();

        foreach (['false', 0, '0', null] as $valor) {
            $respuesta = $this->backoffice->post('/backoffice/usuario/estado', ['id' => $usuario['id'], 'activo' => $valor]);
            $this->assertSame(422, $respuesta->codigo, 'activo = ' . var_export($valor, true));
            $this->assertSame('El estado es inválido', $respuesta->json()['error']);
        }
    }
}
