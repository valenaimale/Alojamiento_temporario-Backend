<?php

namespace Tests\Integracion;

//GET /localidades: la lista cerrada de destinos de la búsqueda y del alta de unidades (US3.2).
final class LocalidadesTest extends CasoDeIntegracion
{
    //La lista se puede pedir sin sesión y trae las localidades con su provincia
    public function testDevuelveLasLocalidadesActivasSinNecesitarSesion(): void
    {
        $respuesta = $this->cliente()->get('/localidades');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $localidades = $respuesta->json()['localidades'];
        $this->assertContains(['id' => 1, 'nombre' => 'Las Grutas', 'provincia' => 'Río Negro'], $localidades);
        $this->assertIsInt($localidades[0]['id']);
    }

    //Vienen ordenadas por provincia y por nombre, cada provincia en un solo bloque
    public function testVienenOrdenadasPorProvinciaYNombre(): void
    {
        $localidades = $this->cliente()->get('/localidades')->json()['localidades'];
        $provincias = array_column($localidades, 'provincia');

        $this->assertSame('Buenos Aires', $provincias[0]);//la primera en orden alfabético
        //cada provincia aparece en un solo bloque (el front las agrupa con <optgroup>)
        $bloques = [];
        foreach ($provincias as $i => $provincia) {
            if ($i === 0 || $provincia !== $provincias[$i - 1]) {
                $this->assertNotContains($provincia, $bloques, "La provincia $provincia aparece en dos bloques");
                $bloques[] = $provincia;
            }
        }
        //dentro de una provincia, por nombre
        $deBuenosAires = array_column(array_filter($localidades, fn ($l) => $l['provincia'] === 'Buenos Aires'), 'nombre');
        $this->assertSame(['Mar de Ajó', 'Mar de las Pampas', 'Mar del Plata', 'Pinamar', 'Villa Gesell'], array_values($deBuenosAires));
    }

    //Una localidad inactiva no aparece en la lista
    public function testNoDevuelveLasInactivas(): void
    {
        $this->pdo()->exec("INSERT INTO localidades (nombre, provincia, activa) VALUES ('Pueblo Cerrado', 'La Pampa', 0)");

        $nombres = array_column($this->cliente()->get('/localidades')->json()['localidades'], 'nombre');

        $this->assertNotContains('Pueblo Cerrado', $nombres);
    }
}
