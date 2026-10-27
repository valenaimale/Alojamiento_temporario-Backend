<?php

namespace Tests\Integracion;

use PHPUnit\Framework\Attributes\DataProvider;

//RN-19 y OWASP n.º 1 (control de acceso): sin sesión responde 401, con un rol sin permiso responde 403.
final class PermisosTest extends CasoDeIntegracion
{
    //Cada ruta protegida responde 401 sin sesión
    #[DataProvider('rutasProtegidas')]
    public function testSinSesionResponde401(string $metodo, string $ruta): void
    {
        $cliente = $this->cliente();
        $respuesta = $metodo === 'GET' ? $cliente->get($ruta) : $cliente->post($ruta, []);

        $this->assertSame(401, $respuesta->codigo, "$metodo $ruta: {$respuesta->cuerpo}");
        $this->assertSame('No hay sesión iniciada', $respuesta->json()['error']);
    }

    //Las rutas protegidas que se prueban sin sesión
    public static function rutasProtegidas(): array
    {
        return [
            'ver la sesión'             => ['GET', '/sesion'],
            'datos fiscales'            => ['GET', '/datos-fiscales'],
            'hacerse propietario'       => ['POST', '/hacerse-propietario'],
            'reenviar verificación'     => ['POST', '/reenviar-verificacion'],
            'completar datos'           => ['POST', '/completar-datos'],
            'listado del backoffice'    => ['GET', '/backoffice/usuarios'],
            'detalle del backoffice'    => ['GET', '/backoffice/usuario'],
            'cambiar estado de cuenta'  => ['POST', '/backoffice/usuario/estado'],
        ];
    }

    //Un usuario con un rol sin permiso recibe 403
    #[DataProvider('accesosDenegados')]
    public function testUnRolSinPermisoResponde403(string $rol, string $metodo, string $ruta): void
    {
        $usuario = $this->crearUsuarioEnBase($rol);
        $cliente = $this->iniciarSesion($usuario['mail']);

        $respuesta = $metodo === 'GET' ? $cliente->get($ruta) : $cliente->post($ruta, []);

        $this->assertSame(403, $respuesta->codigo, "$rol → $metodo $ruta: {$respuesta->cuerpo}");
        $this->assertSame('No tenés permiso para hacer esto', $respuesta->json()['error']);
    }

    //Cada caso: qué rol, qué ruta, y que no la puede usar
    public static function accesosDenegados(): array
    {
        return [
            'huésped al backoffice'                => ['huesped', 'GET', '/backoffice/usuarios'],
            'propietario al backoffice'            => ['propietario', 'GET', '/backoffice/usuarios'],
            'huésped a datos fiscales'             => ['huesped', 'GET', '/datos-fiscales'],
            'operador a hacerse propietario'       => ['operador', 'POST', '/hacerse-propietario'],
            'administrador a hacerse propietario'  => ['administrador', 'POST', '/hacerse-propietario'],
            'backoffice a datos fiscales'          => ['backoffice', 'GET', '/datos-fiscales'],
        ];
    }

    //Si el backoffice deshabilita una cuenta con la sesión abierta, esa sesión deja de servir
    public function testSiDeshabilitanLaCuentaSePierdeLaSesionAbierta(): void
    {
        [$cliente, $datos] = $this->registrar();
        $this->assertSame(200, $cliente->get('/sesion')->codigo);

        $this->pdo()->prepare('UPDATE usuarios SET activo = 0 WHERE mail = ?')->execute([$datos['mail']]);

        $respuesta = $cliente->get('/sesion');
        $this->assertSame(401, $respuesta->codigo);
        $this->assertSame('Tu cuenta está deshabilitada', $respuesta->json()['error']);
    }
}
