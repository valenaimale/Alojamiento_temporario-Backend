<?php

namespace Tests\Integracion;

//La política de cancelación y el % de seña de una unidad (US2.8): verla y cambiarla,
//con la regla seña <= retención de más de 30 <= de 15 a 30 <= de menos de 15.
final class PoliticaTest extends CasoDeIntegracion
{
    //Un propietario con una unidad (con la política por defecto: 20, 30, 50, 70) y su sesión iniciada: [cliente, id de la unidad]
    private function propietarioConUnidad(): array
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        return [$this->iniciarSesion($propietario['mail']), $unidadId];
    }

    //Una política válida para mandar, con los cambios que pida el test
    private function politica(int $unidadId, array $cambios = []): array
    {
        return array_merge([
            'unidad_id'             => $unidadId,
            'porcentaje_sena'       => 25,
            'retencion_mas_de_30'   => 25,
            'retencion_de_15_a_30'  => '40.5',
            'retencion_menos_de_15' => 100,
        ], $cambios);
    }

    //Los 4 porcentajes guardados en la base
    private function guardada(int $unidadId): array
    {
        return $this->pdo()->query(
            "SELECT porcentaje_sena, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15 FROM unidades WHERE id = $unidadId"
        )->fetch();
    }

    //Ver: la seña, las 3 retenciones y los días de cobro del saldo (de parametros)
    public function testVerDevuelveLaPoliticaDeLaUnidad(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $respuesta = $cliente->get('/unidades/politica', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['politica' => [
            'porcentaje_sena'       => '20.00',
            'retencion_mas_de_30'   => '30.00',
            'retencion_de_15_a_30'  => '50.00',
            'retencion_menos_de_15' => '70.00',
            'dias_cobro_saldo'      => 5,
        ]], $respuesta->json());
    }

    //Guardar: acepta números y texto, iguales entre sí (seña igual a la retención más baja) y los extremos 0 y 100
    public function testGuardaLaPolitica(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $respuesta = $cliente->post('/unidades/politica', $this->politica($unidadId));

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $esperada = [
            'porcentaje_sena'       => '25.00',
            'retencion_mas_de_30'   => '25.00',
            'retencion_de_15_a_30'  => '40.50',
            'retencion_menos_de_15' => '100.00',
        ];
        $this->assertSame($esperada, $respuesta->json()['politica']);
        $this->assertSame($esperada, $this->guardada($unidadId));

        $todoCero = $cliente->post('/unidades/politica', $this->politica($unidadId, [
            'porcentaje_sena' => 0, 'retencion_mas_de_30' => 0, 'retencion_de_15_a_30' => 0, 'retencion_menos_de_15' => 0,
        ]));
        $this->assertSame(200, $todoCero->codigo, $todoCero->cuerpo);
    }

    //Cada desigualdad que no se cumple tiene su propio mensaje, y no se guarda nada
    public function testCadaReglaTieneSuMensaje(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $casos = [
            [['porcentaje_sena' => 30, 'retencion_mas_de_30' => 25], 'La seña no puede ser mayor que la retención de más de 30 días'],
            [['retencion_mas_de_30' => 50, 'retencion_de_15_a_30' => 40], 'La retención de más de 30 días no puede ser mayor que la retención de 15 a 30 días'],
            [['retencion_de_15_a_30' => 90, 'retencion_menos_de_15' => 80], 'La retención de 15 a 30 días no puede ser mayor que la retención de menos de 15 días'],
        ];

        foreach ($casos as [$cambios, $mensaje]) {
            $respuesta = $cliente->post('/unidades/politica', $this->politica($unidadId, $cambios));
            $this->assertSame(422, $respuesta->codigo, $mensaje);
            $this->assertSame($mensaje, $respuesta->json()['error']);
        }
        $this->assertSame('20.00', $this->guardada($unidadId)['porcentaje_sena']);
    }

    //Cada porcentaje tiene que ser un número de 0 a 100 con hasta 2 decimales
    public function testValidaCadaPorcentaje(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $casos = [
            'porcentaje_sena'       => [-1, 'La seña'],
            'retencion_mas_de_30'   => ['30.555', 'La retención de más de 30 días'],
            'retencion_de_15_a_30'  => ['cincuenta', 'La retención de 15 a 30 días'],
            'retencion_menos_de_15' => [100.01, 'La retención de menos de 15 días'],
        ];

        foreach ($casos as $campo => [$valor, $nombre]) {
            $respuesta = $cliente->post('/unidades/politica', $this->politica($unidadId, [$campo => $valor]));
            $this->assertSame(422, $respuesta->codigo, $campo);
            $this->assertSame("$nombre tiene que ser un porcentaje de 0 a 100, con hasta 2 decimales", $respuesta->json()['error']);
        }

        $sinSena = $this->politica($unidadId);
        unset($sinSena['porcentaje_sena']);
        $this->assertSame(422, $cliente->post('/unidades/politica', $sinSena)->codigo);
    }

    //Solo el propietario de la unidad: otro propietario 404, el administrador asignado 403, sin sesión 401
    public function testSoloElPropietarioDeLaUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $quienes = [
            'otro propietario'          => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404],
            'el administrador asignado' => [$this->iniciarSesion($administrador['mail']), 403],
            'sin sesión'                => [$this->cliente(), 401],
        ];
        foreach ($quienes as $quien => [$cliente, $codigo]) {
            $this->assertSame($codigo, $cliente->get('/unidades/politica', ['id' => $unidadId])->codigo, "$quien: ver");
            $this->assertSame($codigo, $cliente->post('/unidades/politica', $this->politica($unidadId))->codigo, "$quien: guardar");
        }
        $this->assertSame('20.00', $this->guardada($unidadId)['porcentaje_sena']);
    }
}
