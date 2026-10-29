<?php

namespace Tests\Integracion;

//La agenda del operador (RF-10): GET /operador/agenda. Las reservas en curso y próximas de sus unidades, sin montos (RN-19).
final class AgendaOperadorTest extends CasoDeIntegracion
{
    //Una unidad con ese operador asignado (o sin operador, con null). Devuelve su id.
    private function unidadConOperador(?int $operadorId, array $cambios = []): int
    {
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id'], $cambios);
        $this->pdo()->prepare('UPDATE unidades SET operador_id = ? WHERE id = ?')->execute([$operadorId, $unidadId]);
        return $unidadId;
    }

    //Una reserva guardada directo en la base, con importes (para controlar que no salgan). Devuelve su id.
    private function reservaEnBase(int $unidadId, int $huespedId, string $ingreso, string $egreso, string $estado = 'pendiente'): int
    {
        $this->pdo()->prepare(
            "INSERT INTO reservas (unidad_id, huesped_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado, importe_total,
                                   porcentaje_sena, importe_sena, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15,
                                   politica_aceptada_en)
             VALUES (?, ?, ?, ?, 3, ?, 210000, 20, 42000, 30, 50, 70, UTC_TIMESTAMP())"
        )->execute([$unidadId, $huespedId, $ingreso, $egreso, $estado]);
        return (int) $this->pdo()->lastInsertId();
    }

    //Solo las de sus unidades, en curso y próximas, de la más cercana a la más lejana, con la dirección y el huésped;
    //nunca los montos. No aparecen las terminadas, las canceladas ni las de unidades de otro operador.
    public function testMuestraLasReservasDeSusUnidadesSinMontos(): void
    {
        $operador = $this->crearUsuarioEnBase('operador');
        $suya = $this->unidadConOperador($operador['id'], ['nombre' => 'Casa del Bosque', 'direccion' => 'Los Alerces 450']);
        $deOtro = $this->unidadConOperador($this->crearUsuarioEnBase('operador')['id']);
        $sinOperador = $this->unidadConOperador(null);
        $huespedId = $this->crearUsuarioEnBase('huesped', ['nombre' => 'Juan'])['id'];

        $proxima = $this->reservaEnBase($suya, $huespedId, $this->fecha('+10 days'), $this->fecha('+13 days'), 'confirmada');
        $enCurso = $this->reservaEnBase($suya, $huespedId, $this->fecha('-1 day'), $this->fecha('+2 days'));
        $this->reservaEnBase($suya, $huespedId, $this->fecha('-10 days'), $this->fecha('-5 days'), 'confirmada');//terminó
        $this->reservaEnBase($suya, $huespedId, $this->fecha('+20 days'), $this->fecha('+22 days'), 'cancelada');
        $this->reservaEnBase($deOtro, $huespedId, $this->fecha('+5 days'), $this->fecha('+7 days'));
        $this->reservaEnBase($sinOperador, $huespedId, $this->fecha('+5 days'), $this->fecha('+7 days'));

        $respuesta = $this->iniciarSesion($operador['mail'])->get('/operador/agenda');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $reservas = $respuesta->json()['reservas'];
        $this->assertSame([$enCurso, $proxima], array_column($reservas, 'id'));
        $this->assertSame([
            'id' => $proxima, 'estado' => 'confirmada', 'ingreso' => $this->fecha('+10 days'), 'egreso' => $this->fecha('+13 days'),
            'noches' => 3, 'personas' => 3, 'huesped' => 'Juan',
            'unidad' => ['id' => $suya, 'nombre' => 'Casa del Bosque', 'direccion' => 'Los Alerces 450', 'localidad' => 'Las Grutas', 'provincia' => 'Río Negro'],
        ], $reservas[1]);
        $this->assertStringNotContainsString('importe', $respuesta->cuerpo);//RN-19: el operador nunca ve montos
        $this->assertStringNotContainsString('210000', $respuesta->cuerpo);
    }

    //Un operador sin unidades (o sin reservas) recibe la lista vacía
    public function testSinReservasDevuelveLaListaVacia(): void
    {
        $operador = $this->crearUsuarioEnBase('operador');
        $this->unidadConOperador($operador['id']);

        $this->assertSame(['reservas' => []], $this->iniciarSesion($operador['mail'])->get('/operador/agenda')->json());
    }

    //Solo para operadores: sin sesión 401; huésped, propietario y administrador 403
    public function testSoloParaOperadores(): void
    {
        $this->assertSame(401, $this->cliente()->get('/operador/agenda')->codigo);
        $usuarios = [
            'un huésped'       => $this->crearUsuarioEnBase('huesped'),
            'un propietario'   => $this->crearPropietarioEnBase(),
            'un administrador' => $this->crearAdministradorEnBase(),
        ];
        foreach ($usuarios as $quien => $usuario) {
            $this->assertSame(403, $this->iniciarSesion($usuario['mail'])->get('/operador/agenda')->codigo, $quien);
        }
    }
}
