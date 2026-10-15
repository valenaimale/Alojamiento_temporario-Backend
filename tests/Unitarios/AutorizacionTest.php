<?php

namespace Tests\Unitarios;

use App\Autorizacion\Autorizacion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

//RN-19: cada rol tiene un alcance restringido. El propietario hereda los permisos del huésped;
//operador, administrador y backoffice son cuentas aparte.
final class AutorizacionTest extends TestCase
{
    #[DataProvider('permisos')]
    public function testTienePermiso(string $rolUsuario, string $rolRequerido, bool $esperado): void
    {
        $this->assertSame($esperado, Autorizacion::tienePermiso($rolUsuario, $rolRequerido));
    }

    public static function permisos(): array
    {
        return [
            'huésped hace lo de huésped'               => ['huesped', 'huesped', true],
            'propietario hereda del huésped'           => ['propietario', 'huesped', true],
            'propietario hace lo de propietario'       => ['propietario', 'propietario', true],
            'huésped no hace lo de propietario'        => ['huesped', 'propietario', false],
            'operador no hace lo de huésped'           => ['operador', 'huesped', false],
            'administrador no hace lo de propietario'  => ['administrador', 'propietario', false],
            'backoffice no hace lo de huésped'         => ['backoffice', 'huesped', false],
            'huésped no entra al backoffice'           => ['huesped', 'backoffice', false],
            'backoffice entra al backoffice'           => ['backoffice', 'backoffice', true],
            'un rol inexistente no tiene permisos'     => ['superadmin', 'backoffice', false],
        ];
    }
}
