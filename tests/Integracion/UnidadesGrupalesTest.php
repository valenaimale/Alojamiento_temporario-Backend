<?php

namespace Tests\Integracion;

//Las unidades grupales (US4.4): el administrador las arma con unidades que administra, y la búsqueda las ofrece
//armadas para las fechas y las personas del huésped, solo con las unidades libres (decisiones del 20/11).
//Cada test crea su propia localidad, así la búsqueda no se mezcla con las unidades de otros tests.
final class UnidadesGrupalesTest extends CasoDeIntegracion
{
    use EscenaGrupal;

    // ---------- El administrador arma sus grupales ----------

    //Arma una grupal con tres cabañas de dos propietarios distintos: nace activa y suma la capacidad de las tres
    public function testElAdministradorArmaUnaGrupalConUnidadesQueAdministra(): void
    {
        // Arrange
        $escena = $this->escena();

        // Act
        $respuesta = $escena['cliente']->post('/administrador/grupales/crear', [
            'titulo' => 'Cabañas 4, 5 y 6', 'unidad_ids' => $escena['unidades'],
        ]);
        $listado = $escena['cliente']->get('/administrador/grupales');

        // Assert
        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $grupal = $this->delListado($listado->json()['grupales'], $respuesta->json()['id']);
        $this->assertSame('Cabañas 4, 5 y 6', $grupal['titulo']);
        $this->assertTrue($grupal['activa']);
        $this->assertSame(12, $grupal['capacidad']);
        $this->assertFalse($grupal['incompleta']);
        $this->assertCount(3, $grupal['unidades']);
        $this->assertCount(2, array_unique(array_column($grupal['unidades'], 'propietario')));
    }

    //Lo que no se puede guardar: unidades ajenas, de otra localidad, menos de 2, más de 10 o un título vacío
    public function testNoSeGuardaUnaGrupalInvalida(): void
    {
        // Arrange
        $escena = $this->escena();
        $ajena = $this->crearUnidadEnBase($escena['propietarias'][0]['id'], ['localidad_id' => $escena['localidad']]);
        $deOtraLocalidad = $this->unidadAdministrada($escena, $this->localidadNueva());
        [$a, $b] = $escena['unidades'];

        $casos = [
            'unidad que no administra' => [[$a, $ajena], 'Solo podés agrupar unidades que administrás'],
            'otra localidad'           => [[$a, $deOtraLocalidad], 'Todas las unidades de una reserva grupal tienen que ser de la misma localidad'],
            'una sola unidad'          => [[$a, $a], 'Una reserva grupal tiene de 2 a 10 unidades'],
            'más de diez'              => [range(1, 11), 'Una reserva grupal tiene de 2 a 10 unidades'],
            'ids que no son números'   => [[$a, 'hola'], 'Elegí las unidades de la reserva grupal'],
        ];

        foreach ($casos as $caso => [$ids, $mensaje]) {
            // Act
            $respuesta = $escena['cliente']->post('/administrador/grupales/crear', ['titulo' => 'Grupo', 'unidad_ids' => $ids]);

            // Assert
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame($mensaje, $respuesta->json()['error'], $caso);
        }
        $sinTitulo = $escena['cliente']->post('/administrador/grupales/crear', ['titulo' => ' ', 'unidad_ids' => [$a, $b]]);
        $this->assertSame(422, $sinTitulo->codigo);
        $this->assertSame(0, $this->grupalesDe($escena['administrador']['id']));
    }

    //Solo el administrador gestiona grupales, y solo las suyas: la de otro "no existe"
    public function testSoloElAdministradorGestionaSusGrupales(): void
    {
        // Arrange
        $escena = $this->escena();
        $id = $this->crearGrupal($escena);
        $otro = $this->crearAdministradorEnBase();
        $propietaria = $this->iniciarSesion($escena['propietarias'][0]['mail']);

        // Act y Assert
        $this->assertSame(403, $propietaria->get('/administrador/grupales')->codigo);
        $this->assertSame(401, $this->cliente()->post('/administrador/grupales/crear', [])->codigo);
        $otroCliente = $this->iniciarSesion($otro['mail']);
        $this->assertSame(404, $otroCliente->post('/administrador/grupales/editar', [
            'id' => $id, 'titulo' => 'Mío', 'unidad_ids' => $escena['unidades'],
        ])->codigo);
        $this->assertSame(404, $otroCliente->post('/administrador/grupales/estado', ['id' => $id, 'activa' => false])->codigo);
        $this->assertSame([], $otroCliente->get('/administrador/grupales')->json()['grupales']);
    }

    //Editar cambia el título y las unidades
    public function testEditarCambiaElTituloYLasUnidades(): void
    {
        // Arrange
        $escena = $this->escena();
        $id = $this->crearGrupal($escena);

        // Act
        $respuesta = $escena['cliente']->post('/administrador/grupales/editar', [
            'id' => $id, 'titulo' => 'Solo la 4 y la 5', 'unidad_ids' => array_slice($escena['unidades'], 0, 2),
        ]);

        // Assert
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $grupal = $this->delListado($escena['cliente']->get('/administrador/grupales')->json()['grupales'], $id);
        $this->assertSame('Solo la 4 y la 5', $grupal['titulo']);
        $this->assertSame(array_slice($escena['unidades'], 0, 2), array_column($grupal['unidades'], 'id'));
    }

    // ---------- La búsqueda ----------

    //Para 10 personas ninguna cabaña alcanza sola: aparece la grupal con las tres (12 lugares) y ninguna suelta
    public function testLaBusquedaMuestraLaGrupalCuandoNingunaUnidadAlcanzaSola(): void
    {
        // Arrange
        $escena = $this->escena();
        $id = $this->crearGrupal($escena);

        // Act
        $respuesta = $this->buscar($escena, 10);

        // Assert
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([], $respuesta->json()['unidades']);
        $grupales = $respuesta->json()['grupales'];
        $this->assertCount(1, $grupales);
        $this->assertSame($id, $grupales[0]['id']);
        $this->assertSame(12, $grupales[0]['capacidad']);
        $this->assertSame(3, $grupales[0]['noches']);
        $this->assertSame('630000.00', $grupales[0]['precio_total']);//3 cabañas × 3 noches × $70.000
        $this->assertSame($escena['unidades'], array_column($grupales[0]['unidades'], 'id'));
    }

    //Para 3 personas alcanza una cabaña sola: aparecen las sueltas y no la grupal
    public function testParaPocasPersonasAparecenLasUnidadesSueltas(): void
    {
        $escena = $this->escena();
        $this->crearGrupal($escena);

        $respuesta = $this->buscar($escena, 3);

        $this->assertSame([], $respuesta->json()['grupales']);
        $this->assertCount(3, $respuesta->json()['unidades']);
    }

    //Una cabaña ocupada queda afuera: para 8 personas se ofrecen las otras dos; para 10 ya no alcanza
    public function testUnaUnidadOcupadaQuedaAfueraDelGrupo(): void
    {
        // Arrange
        $escena = $this->escena();
        $this->crearGrupal($escena);
        $this->crearBloqueoEnBase($escena['unidades'][0], $this->fecha('+10 days'), $this->fecha('+11 days'), $escena['propietarias'][0]['id']);

        // Act
        $paraOcho = $this->buscar($escena, 8)->json()['grupales'];
        $paraDiez = $this->buscar($escena, 10)->json()['grupales'];

        // Assert
        $this->assertCount(1, $paraOcho);
        $this->assertSame(array_slice($escena['unidades'], 1), array_column($paraOcho[0]['unidades'], 'id'));
        $this->assertSame(8, $paraOcho[0]['capacidad']);
        $this->assertSame([], $paraDiez);
    }

    //Una cabaña que exige más noches de las que se buscan también queda afuera, y las otras dos se ofrecen
    public function testUnaUnidadSinElMinimoDeNochesQuedaAfuera(): void
    {
        $escena = $this->escena();
        $this->crearGrupal($escena);
        $this->crearTemporadaEnBase($escena['unidades'][2], 'alta', $this->fecha('+1 day'), $this->fecha('+60 days'), 7);

        $grupales = $this->buscar($escena, 8)->json()['grupales'];

        $this->assertSame(array_slice($escena['unidades'], 0, 2), array_column($grupales[0]['unidades'], 'id'));
    }

    //Con una sola cabaña libre no hay grupal: esa cabaña ya aparece sola
    public function testConUnaSolaUnidadLibreNoHayGrupal(): void
    {
        $escena = $this->escena();
        $this->crearGrupal($escena);
        foreach (array_slice($escena['unidades'], 0, 2) as $unidadId) {
            $this->crearBloqueoEnBase($unidadId, $this->fecha('+10 days'), $this->fecha('+11 days'), $escena['propietarias'][0]['id']);
        }

        $this->assertSame([], $this->buscar($escena, 4)->json()['grupales']);
        $this->assertSame([], $this->buscar($escena, 5)->json()['grupales']);
    }

    //Si el administrador deja de administrar una cabaña, sale sola del grupo
    public function testSiDejaDeAdministrarUnaUnidadSaleDelGrupo(): void
    {
        // Arrange
        $escena = $this->escena();
        $id = $this->crearGrupal($escena);
        $this->pdo()->prepare('DELETE FROM administraciones WHERE unidad_id = ?')->execute([$escena['unidades'][0]]);

        // Act
        $grupal = $this->delListado($escena['cliente']->get('/administrador/grupales')->json()['grupales'], $id);
        $paraOcho = $this->buscar($escena, 8)->json()['grupales'];

        // Assert
        $this->assertFalse($grupal['unidades'][0]['vigente']);
        $this->assertSame(8, $grupal['capacidad']);
        $this->assertSame(array_slice($escena['unidades'], 1), array_column($paraOcho[0]['unidades'], 'id'));
    }

    //Desactivada no aparece; para volver a activarla hacen falta 2 unidades que siga administrando
    public function testUnaGrupalDesactivadaNoAparece(): void
    {
        // Arrange
        $escena = $this->escena();
        $id = $this->crearGrupal($escena);

        // Act
        $desactivar = $escena['cliente']->post('/administrador/grupales/estado', ['id' => $id, 'activa' => false]);
        $grupales = $this->buscar($escena, 10)->json()['grupales'];
        $this->pdo()->prepare('DELETE FROM administraciones WHERE unidad_id IN (?, ?)')->execute(array_slice($escena['unidades'], 0, 2));
        $activar = $escena['cliente']->post('/administrador/grupales/estado', ['id' => $id, 'activa' => true]);

        // Assert
        $this->assertSame(200, $desactivar->codigo, $desactivar->cuerpo);
        $this->assertSame([], $grupales);
        $this->assertSame(409, $activar->codigo);
        $this->assertTrue($this->delListado($escena['cliente']->get('/administrador/grupales')->json()['grupales'], $id)['incompleta']);
    }

    // ---------- El detalle ----------

    //El detalle trae cada cabaña libre completa: fotos, seña, política y precio de su estadía
    public function testElDetalleTraeCadaUnidadConSuSenaYSuPolitica(): void
    {
        // Arrange
        $escena = $this->escena();
        $id = $this->crearGrupal($escena);
        $this->pdo()->prepare('UPDATE unidades SET porcentaje_sena = 30, retencion_menos_de_15 = 90 WHERE id = ?')->execute([$escena['unidades'][1]]);

        // Act
        $respuesta = $this->cliente()->get('/grupal', $this->parametros($escena, 10) + ['id' => $id]);

        // Assert
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $grupal = $respuesta->json()['grupal'];
        $this->assertSame('Cabañas del grupo', $grupal['titulo']);
        $this->assertSame('630000.00', $grupal['precio_total']);
        $this->assertCount(3, $grupal['unidades']);
        $segunda = $grupal['unidades'][1];
        $this->assertSame('30.00', $segunda['porcentaje_sena']);
        $this->assertSame('63000.00', $segunda['estadia']['sena']);//30 % de $210.000
        $this->assertSame('90.00', $segunda['politica']['retencion_menos_de_15']);
        $this->assertNotEmpty($segunda['fotos']);
    }

    //Si para esas personas no corresponde, el detalle da 409; una desactivada o inexistente, 404
    public function testElDetalleSoloCorrespondeALaBusqueda(): void
    {
        $escena = $this->escena();
        $id = $this->crearGrupal($escena);

        $paraTres = $this->cliente()->get('/grupal', $this->parametros($escena, 3) + ['id' => $id]);
        $escena['cliente']->post('/administrador/grupales/estado', ['id' => $id, 'activa' => false]);
        $desactivada = $this->cliente()->get('/grupal', $this->parametros($escena, 10) + ['id' => $id]);

        $this->assertSame(409, $paraTres->codigo);
        $this->assertSame(404, $desactivada->codigo);
        $this->assertSame(404, $this->cliente()->get('/grupal', $this->parametros($escena, 10) + ['id' => 'x'])->codigo);
    }

    // ---------- Ayudas de este test ----------

    //Busca en la localidad de la escena, sin sesión
    private function buscar(array $escena, int $personas)
    {
        return $this->cliente()->get('/buscar', $this->parametros($escena, $personas));
    }

    //Una grupal del listado del administrador, por id
    private function delListado(array $grupales, int $id): array
    {
        foreach ($grupales as $grupal) {
            if ($grupal['id'] === $id) {
                return $grupal;
            }
        }
        $this->fail("La grupal $id no está en el listado");
    }

    //Cuántas grupales tiene un administrador en la base
    private function grupalesDe(int $administradorId): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM unidades_grupales WHERE administrador_id = $administradorId")->fetchColumn();
    }
}
