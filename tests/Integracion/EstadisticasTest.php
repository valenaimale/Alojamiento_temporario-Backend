<?php

namespace Tests\Integracion;

use App\Validacion\Devolucion;
use App\Validacion\Estadisticas;

//GET /unidades/estadisticas (RF-12): quién puede verlas, qué temporada muestra por defecto y que los números
//salgan de las reservas que hay en la base. Las cuentas en sí se prueban en Tests\Unitarios\EstadisticasTest.
final class EstadisticasTest extends CasoDeIntegracion
{
    private const PRECIO_NOCHE = 81400;

    public function testElPropietarioVeLasEstadisticasDeSuTemporada(): void
    {
        ['cliente' => $cliente, 'unidad' => $unidad, 'temporada' => $temporada] = $this->unidadConTemporada();

        $respuesta = $cliente->get('/unidades/estadisticas', ['id' => $unidad, 'temporada_id' => $temporada]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $cuerpo = $respuesta->json();
        $this->assertSame($unidad, $cuerpo['unidad']['id']);
        $this->assertSame($temporada, $cuerpo['temporada']['id']);

        $estadisticas = $cuerpo['estadisticas'];
        $this->assertTrue($estadisticas['hay_datos']);
        $this->assertSame(50, $estadisticas['noches_disponibles']);
        $this->assertSame(10, $estadisticas['noches_ocupadas']);//una de 4 noches y otra de 6
        $this->assertSame('20.0', $estadisticas['ocupacion']);
        $this->assertSame('81400.00', $estadisticas['tarifa_promedio']);
        $this->assertSame('5.0', $estadisticas['estadia_promedio']);
        $this->assertSame(2, $estadisticas['reservas']);
        $this->assertSame(1, $estadisticas['huespedes_recurrentes']);//el mismo huésped reservó las dos veces
        $this->assertSame(1, $estadisticas['cancelaciones'][Devolucion::DE_15_A_30]);
        $this->assertSame(0, $estadisticas['cancelaciones'][Devolucion::MAS_DE_30]);
        $this->assertSame(0, $estadisticas['cancelaciones'][Estadisticas::OTRAS_CANCELACIONES]);
    }

    //El administrador asignado a la unidad ve lo mismo que el propietario: es quien la gestiona
    public function testElAdministradorAsignadoTambienLasVe(): void
    {
        ['unidad' => $unidad, 'temporada' => $temporada] = $this->unidadConTemporada();
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidad, $administrador['id']);

        $respuesta = $this->iniciarSesion($administrador['mail'])
            ->get('/unidades/estadisticas', ['id' => $unidad, 'temporada_id' => $temporada]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('20.0', $respuesta->json()['estadisticas']['ocupacion']);
    }

    //Las estadísticas de una unidad ajena no se ven, y se responde 404 para no confirmar que existe
    public function testOtroPropietarioNoVeLaUnidadAjena(): void
    {
        ['unidad' => $unidad] = $this->unidadConTemporada();
        $otro = $this->crearPropietarioEnBase();

        $respuesta = $this->iniciarSesion($otro['mail'])->get('/unidades/estadisticas', ['id' => $unidad]);

        $this->assertSame(404, $respuesta->codigo);
        $this->assertSame('No encontramos esa unidad', $respuesta->json()['error']);
    }

    public function testSinSesionNoSeVen(): void
    {
        ['unidad' => $unidad] = $this->unidadConTemporada();

        $respuesta = $this->cliente()->get('/unidades/estadisticas', ['id' => $unidad]);

        $this->assertSame(401, $respuesta->codigo);
    }

    //Una temporada de otra unidad no se puede mirar colgándose de una unidad propia
    public function testLaTemporadaDeOtraUnidadNoSePuedePedir(): void
    {
        ['cliente' => $cliente, 'unidad' => $unidad] = $this->unidadConTemporada();
        ['temporada' => $ajena] = $this->unidadConTemporada();

        $respuesta = $cliente->get('/unidades/estadisticas', ['id' => $unidad, 'temporada_id' => $ajena]);

        $this->assertSame(404, $respuesta->codigo);
        $this->assertSame('No encontramos esa temporada', $respuesta->json()['error']);
    }

    //Sin temporada_id muestra la que está en curso, no la más nueva ni la más vieja
    public function testSinTemporadaMuestraLaQueEstaEnCurso(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidad = $this->crearUnidadEnBase($propietario['id']);
        $this->crearTemporadaEnBase($unidad, 'baja', $this->fecha('-200 days'), $this->fecha('-150 days'));
        $enCurso = $this->crearTemporadaEnBase($unidad, 'alta', $this->fecha('-10 days'), $this->fecha('+10 days'));
        $this->crearTemporadaEnBase($unidad, 'media', $this->fecha('+100 days'), $this->fecha('+150 days'));

        $respuesta = $this->iniciarSesion($propietario['mail'])->get('/unidades/estadisticas', ['id' => $unidad]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame($enCurso, $respuesta->json()['temporada']['id']);
        $this->assertCount(3, $respuesta->json()['temporadas']);
        //la temporada arrancó hace 10 días: se miden esas 10 noches, no las 21 que dura
        $this->assertSame(10, $respuesta->json()['estadisticas']['noches_disponibles']);
    }

    //La temporada cargada pero todavía sin reservas: el front muestra "Todavía no hay datos de esta temporada"
    public function testLaTemporadaSinReservasAvisaQueNoHayDatos(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidad = $this->crearUnidadEnBase($propietario['id']);
        $temporada = $this->crearTemporadaEnBase($unidad, 'alta', $this->fecha('-60 days'), $this->fecha('-11 days'));

        $respuesta = $this->iniciarSesion($propietario['mail'])
            ->get('/unidades/estadisticas', ['id' => $unidad, 'temporada_id' => $temporada]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertFalse($respuesta->json()['estadisticas']['hay_datos']);
        $this->assertSame('0.0', $respuesta->json()['estadisticas']['ocupacion']);
    }

    //Una unidad sin ninguna temporada cargada no es el estado vacío: primero hay que cargar una temporada
    public function testLaUnidadSinTemporadasVuelveEnNull(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidad = $this->crearUnidadEnBase($propietario['id']);

        $respuesta = $this->iniciarSesion($propietario['mail'])->get('/unidades/estadisticas', ['id' => $unidad]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([], $respuesta->json()['temporadas']);
        $this->assertNull($respuesta->json()['temporada']);
        $this->assertNull($respuesta->json()['estadisticas']);
    }

    //Una unidad de un propietario, con una temporada de 50 noches ya terminada y tres reservas dentro:
    //dos del mismo huésped que ocuparon 4 y 6 noches, y una cancelada 30 días antes del cobro del saldo.
    //Devuelve ['cliente' => el propietario con la sesión iniciada, 'unidad' => id, 'temporada' => id].
    private function unidadConTemporada(): array
    {
        $propietario = $this->crearPropietarioEnBase();
        $unidad = $this->crearUnidadEnBase($propietario['id']);
        $temporada = $this->crearTemporadaEnBase($unidad, 'alta', $this->fecha('-60 days'), $this->fecha('-11 days'));
        $huesped = $this->crearUsuarioEnBase('huesped');

        $this->crearReservaEnBase($unidad, $huesped['id'], [
            'fecha_ingreso' => $this->fecha('-60 days'),
            'fecha_egreso'  => $this->fecha('-56 days'),//4 noches
            'estado'        => 'finalizada',
            'importe_total' => self::PRECIO_NOCHE * 4,
        ]);
        $this->crearReservaEnBase($unidad, $huesped['id'], [
            'fecha_ingreso' => $this->fecha('-50 days'),
            'fecha_egreso'  => $this->fecha('-44 days'),//6 noches
            'estado'        => 'confirmada',
            'importe_total' => self::PRECIO_NOCHE * 6,
        ]);
        $this->crearReservaEnBase($unidad, $this->crearUsuarioEnBase('huesped')['id'], [
            'fecha_ingreso'  => $this->fecha('-40 days'),
            'fecha_egreso'   => $this->fecha('-38 days'),
            'estado'         => 'cancelada',
            'importe_total'  => self::PRECIO_NOCHE * 2,
            'fecha_cobro'    => $this->fecha('-70 days'),
            'cancelada_en'   => $this->fecha('-100 days') . ' 09:30:00',//30 días antes del cobro
            'cancelada_por'  => 'huesped',
        ]);

        return ['cliente' => $this->iniciarSesion($propietario['mail']), 'unidad' => $unidad, 'temporada' => $temporada];
    }

    //Una reserva de la unidad con los campos que importan para las estadísticas; el resto van con valores válidos
    private function crearReservaEnBase(int $unidadId, int $huespedId, array $cambios): int
    {
        $reserva = array_merge([
            'unidad_id'             => $unidadId,
            'huesped_id'            => $huespedId,
            'fecha_ingreso'         => $this->fecha('-60 days'),
            'fecha_egreso'          => $this->fecha('-56 days'),
            'cantidad_personas'     => 2,
            'estado'                => 'finalizada',
            'importe_total'         => self::PRECIO_NOCHE * 4,
            'porcentaje_sena'       => 20,
            'importe_sena'          => self::PRECIO_NOCHE,
            'retencion_mas_de_30'   => 30,
            'retencion_de_15_a_30'  => 50,
            'retencion_menos_de_15' => 70,
            'fecha_cobro'           => null,
            'cancelada_en'          => null,
            'cancelada_por'         => null,
        ], $cambios);

        $columnas = implode(', ', array_keys($reserva));
        $marcadores = implode(', ', array_fill(0, count($reserva), '?'));
        $this->pdo()
            ->prepare("INSERT INTO reservas ($columnas, politica_aceptada_en) VALUES ($marcadores, UTC_TIMESTAMP())")
            ->execute(array_values($reserva));
        return (int) $this->pdo()->lastInsertId();
    }
}
