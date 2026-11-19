<?php

namespace Tests\Unitarios;

use App\Validacion\Ubicacion;
use PHPUnit\Framework\TestCase;

// Reglas del punto que el propietario hace público.
final class UbicacionTest extends TestCase
{
    // Una coordenada sola nunca identifica una unidad.
    public function test_RechazaUnPuntoIncompleto(): void
    {
        // Arrange
        $datos = ['latitud' => 0];
        // Act
        $resultado = Ubicacion::validar($datos);
        // Assert
        $this->assertArrayHasKey('error', $resultado);
    }
}
