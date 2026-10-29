<?php

namespace Tests\Integracion;

use GuzzleHttp\Client;
use Tests\Soporte\ClienteApi;

//Quién gestiona una unidad (US2.2 a US2.4): ver la gestión; asignar, cambiar la comisión y quitar el administrador
//(solo el propietario); y asignar y quitar el operador (el propietario o el administrador).
final class GestionUnidadTest extends CasoDeIntegracion
{
    //Un propietario (Marisa) con una unidad y su sesión iniciada: [cliente, id de la unidad, el propietario]
    private function propietarioConUnidad(): array
    {
        $propietario = $this->crearPropietarioEnBase(['nombre' => 'Marisa']);
        $unidadId = $this->crearUnidadEnBase($propietario['id'], ['nombre' => 'Cabaña del Mar']);
        return [$this->iniciarSesion($propietario['mail']), $unidadId, $propietario];
    }

    //Asigna un administrador por la API y devuelve la respuesta (el test controla el código)
    private function asignar(ClienteApi $cliente, int $unidadId, string $mail, mixed $comision = 15)
    {
        return $cliente->post('/unidades/administrador/asignar', ['unidad_id' => $unidadId, 'mail' => $mail, 'porcentaje_comision' => $comision]);
    }

    //La fila de administraciones de la unidad (false si es de autogestión)
    private function administracion(int $unidadId): array|false
    {
        return $this->pdo()->query("SELECT administrador_id, porcentaje_comision FROM administraciones WHERE unidad_id = $unidadId")->fetch();
    }

    // ---------- ver ----------

    //Sin administrador ni operador: los dos en null
    public function testUnaUnidadDeAutogestion(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $respuesta = $cliente->get('/unidades/gestion', ['id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['administrador' => null, 'operador' => null, 'es_propietario' => true], $respuesta->json());
    }

    //Con administrador: su nombre, su mail, su comisión y desde cuándo. El administrador también la ve.
    public function testConAdministradorLoVenElPropietarioYElAdministrador(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $administrador = $this->crearAdministradorEnBase(['nombre' => 'Cacho']);
        $this->asignarAdministradorEnBase($unidadId, $administrador['id'], 15);

        $gestion = $cliente->get('/unidades/gestion', ['id' => $unidadId])->json();
        $this->assertSame('Cacho', $gestion['administrador']['nombre']);
        $this->assertSame($administrador['mail'], $gestion['administrador']['mail']);
        $this->assertSame('15.00', $gestion['administrador']['porcentaje_comision']);
        $this->assertNotEmpty($gestion['administrador']['desde']);

        $comoAdministrador = $this->iniciarSesion($administrador['mail'])->get('/unidades/gestion', ['id' => $unidadId]);
        $this->assertSame(200, $comoAdministrador->codigo, $comoAdministrador->cuerpo);
        $this->assertFalse($comoAdministrador->json()['es_propietario']);
    }

    // ---------- asignar ----------

    //El propietario asigna un administrador por su mail (sin importar mayúsculas ni espacios), con su comisión
    public function testAsignaUnAdministradorPorMail(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $administrador = $this->crearAdministradorEnBase(['nombre' => 'Cacho']);

        $respuesta = $this->asignar($cliente, $unidadId, '  ' . strtoupper($administrador['mail']) . ' ', '17.5');

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Asignaste a Cacho como administrador', $respuesta->json()['ok']);
        $this->assertSame(['administrador_id' => $administrador['id'], 'porcentaje_comision' => '17.50'], $this->administracion($unidadId));
    }

    //Solo una cuenta activa con rol administrador: ni otro rol, ni una cuenta deshabilitada, ni un mail que no existe
    public function testSoloUnAdministradorActivo(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $inactivo = $this->crearAdministradorEnBase();
        $this->pdo()->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$inactivo['id']]);//deshabilitado por el backoffice
        $mails = [
            'un huésped'                => $this->crearUsuarioEnBase('huesped')['mail'],
            'un operador'               => $this->crearUsuarioEnBase('operador')['mail'],
            'otro propietario'          => $this->crearPropietarioEnBase()['mail'],
            'un administrador inactivo' => $inactivo['mail'],
            'un mail que no existe'     => 'nadie@ejemplo.local',
            'sin mail'                  => '',
        ];

        foreach ($mails as $caso => $mail) {
            $respuesta = $this->asignar($cliente, $unidadId, $mail);
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame('No hay un administrador registrado con ese mail', $respuesta->json()['error'], $caso);
        }
        $this->assertFalse($this->administracion($unidadId));
    }

    //La comisión es obligatoria (RN-05): mayor que 0 y menor que 100, con hasta 2 decimales
    public function testLaComisionEsObligatoriaYValida(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $mail = $this->crearAdministradorEnBase()['mail'];

        foreach (['', 0, 100, 100.5, -5, '15.555', 'quince', '15,5'] as $comision) {
            $respuesta = $this->asignar($cliente, $unidadId, $mail, $comision);
            $this->assertSame(422, $respuesta->codigo, var_export($comision, true));
            $this->assertSame('La comisión tiene que ser un porcentaje mayor que 0 y menor que 100, con hasta 2 decimales', $respuesta->json()['error']);
        }
        $this->assertFalse($this->administracion($unidadId));

        $this->assertSame(200, $this->asignar($cliente, $unidadId, $mail, '99.99')->codigo);
    }

    //Una unidad tiene como mucho un administrador: para cambiarlo, primero se quita el actual
    public function testUnSegundoAdministradorDa409(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $primero = $this->crearAdministradorEnBase();
        $this->asignar($cliente, $unidadId, $primero['mail']);

        $respuesta = $this->asignar($cliente, $unidadId, $this->crearAdministradorEnBase()['mail']);

        $this->assertSame(409, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('La unidad ya tiene administrador. Quitalo antes de asignar otro.', $respuesta->json()['error']);
        $this->assertSame($primero['id'], $this->administracion($unidadId)['administrador_id']);
    }

    // ---------- comisión y quitar ----------

    //Cambiar la comisión; el mismo valor no cambia nada; sin administrador da 409
    public function testCambiarLaComision(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $cambiar = fn ($comision) => $cliente->post('/unidades/administrador/comision', ['unidad_id' => $unidadId, 'porcentaje_comision' => $comision]);

        $sinAdministrador = $cambiar(20);
        $this->assertSame(409, $sinAdministrador->codigo);
        $this->assertSame('La unidad no tiene administrador', $sinAdministrador->json()['error']);

        $this->asignarAdministradorEnBase($unidadId, $this->crearAdministradorEnBase()['id'], 15);
        $this->assertSame('Cambiaste la comisión', $cambiar(18)->json()['ok']);
        $this->assertSame('18.00', $this->administracion($unidadId)['porcentaje_comision']);
        $this->assertSame('La comisión ya era esa', $cambiar('18.00')->json()['ok']);
        $this->assertSame(422, $cambiar(0)->codigo);
    }

    //Quitar el administrador: la unidad vuelve a autogestión, y él deja de verla
    public function testQuitarElAdministrador(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $administrador = $this->crearAdministradorEnBase(['nombre' => 'Cacho']);
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);

        $respuesta = $cliente->post('/unidades/administrador/quitar', ['unidad_id' => $unidadId]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Quitaste a Cacho: la unidad vuelve a ser de autogestión', $respuesta->json()['ok']);
        $this->assertFalse($this->administracion($unidadId));
        $this->assertSame(404, $this->iniciarSesion($administrador['mail'])->get('/unidades/resumen', ['id' => $unidadId])->codigo);
        $this->assertSame(409, $cliente->post('/unidades/administrador/quitar', ['unidad_id' => $unidadId])->codigo);//ya no tiene
    }

    // ---------- operador ----------

    //El operador lo asignan y lo quitan el propietario y también el administrador de la unidad
    public function testElOperadorLoManejanElPropietarioYElAdministrador(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);
        $nadia = $this->crearUsuarioEnBase('operador', ['nombre' => 'Nadia']);
        $operadorDe = fn () => $this->pdo()->query("SELECT operador_id FROM unidades WHERE id = $unidadId")->fetchColumn();

        $asignar = $cliente->post('/unidades/operador/asignar', ['unidad_id' => $unidadId, 'mail' => $nadia['mail']]);
        $this->assertSame(200, $asignar->codigo, $asignar->cuerpo);
        $this->assertSame('Asignaste a Nadia como operador', $asignar->json()['ok']);
        $this->assertSame($nadia['id'], $operadorDe());
        $this->assertSame(['nombre' => 'Nadia', 'mail' => $nadia['mail']], $cliente->get('/unidades/gestion', ['id' => $unidadId])->json()['operador']);

        $comoAdministrador = $this->iniciarSesion($administrador['mail']);
        $quitar = $comoAdministrador->post('/unidades/operador/quitar', ['unidad_id' => $unidadId]);
        $this->assertSame(200, $quitar->codigo, $quitar->cuerpo);
        $this->assertNull($operadorDe());

        $this->assertSame(200, $comoAdministrador->post('/unidades/operador/asignar', ['unidad_id' => $unidadId, 'mail' => $nadia['mail']])->codigo);
        $this->assertSame($nadia['id'], $operadorDe());
    }

    //Solo una cuenta activa con rol operador; una unidad tiene como mucho un operador; quitar sin operador da 409
    public function testLasReglasDelOperador(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $asignar = fn (string $mail) => $cliente->post('/unidades/operador/asignar', ['unidad_id' => $unidadId, 'mail' => $mail]);
        $inactivo = $this->crearUsuarioEnBase('operador');
        $this->pdo()->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$inactivo['id']]);

        foreach ([$this->crearAdministradorEnBase()['mail'], $this->crearUsuarioEnBase('huesped')['mail'], $inactivo['mail'], 'nadie@ejemplo.local'] as $mail) {
            $respuesta = $asignar($mail);
            $this->assertSame(422, $respuesta->codigo, $mail);
            $this->assertSame('No hay un operador registrado con ese mail', $respuesta->json()['error']);
        }

        $this->assertSame(409, $cliente->post('/unidades/operador/quitar', ['unidad_id' => $unidadId])->codigo);
        $this->assertSame(200, $asignar($this->crearUsuarioEnBase('operador')['mail'])->codigo);
        $segundo = $asignar($this->crearUsuarioEnBase('operador')['mail']);
        $this->assertSame(409, $segundo->codigo);
        $this->assertSame('La unidad ya tiene operador. Quitalo antes de asignar otro.', $segundo->json()['error']);
    }

    //Un operador puede tener varias unidades
    public function testUnOperadorPuedeTenerVariasUnidades(): void
    {
        [$cliente, $unaUnidad, $propietario] = $this->propietarioConUnidad();
        $otraUnidad = $this->crearUnidadEnBase($propietario['id']);
        $nadia = $this->crearUsuarioEnBase('operador');

        foreach ([$unaUnidad, $otraUnidad] as $unidadId) {
            $this->assertSame(200, $cliente->post('/unidades/operador/asignar', ['unidad_id' => $unidadId, 'mail' => $nadia['mail']])->codigo);
        }
        $this->assertSame(2, (int) $this->pdo()->query("SELECT COUNT(*) FROM unidades WHERE operador_id = {$nadia['id']}")->fetchColumn());
    }

    //Nadie más maneja el operador: otro propietario, otro administrador y el mismo operador reciben 404; sin sesión, 401
    public function testNadieMasManejaElOperador(): void
    {
        [, $unidadId] = $this->propietarioConUnidad();
        $nadia = $this->crearUsuarioEnBase('operador');
        $this->pdo()->prepare('UPDATE unidades SET operador_id = ? WHERE id = ?')->execute([$nadia['id'], $unidadId]);

        $quienes = [
            'otro propietario'     => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404],
            'otro administrador'   => [$this->iniciarSesion($this->crearAdministradorEnBase()['mail']), 404],
            'el operador asignado' => [$this->iniciarSesion($nadia['mail']), 404],
            'sin sesión'           => [$this->cliente(), 401],
        ];
        foreach ($quienes as $quien => [$cliente, $codigo]) {
            $this->assertSame($codigo, $cliente->post('/unidades/operador/quitar', ['unidad_id' => $unidadId])->codigo, "$quien: quitar");
            $this->assertSame($codigo, $cliente->post('/unidades/operador/asignar', ['unidad_id' => $unidadId, 'mail' => $nadia['mail']])->codigo, "$quien: asignar");
        }
        $this->assertSame($nadia['id'], $this->pdo()->query("SELECT operador_id FROM unidades WHERE id = $unidadId")->fetchColumn());
    }

    // ---------- permisos ----------

    //Asignar, cambiar la comisión y quitar son solo del propietario: el administrador asignado recibe 403,
    //otro propietario 404 y sin sesión 401. Ver la gestión: tampoco otro administrador ni un operador (404).
    public function testSoloElPropietarioManejaElAdministrador(): void
    {
        [, $unidadId] = $this->propietarioConUnidad();
        $administrador = $this->crearAdministradorEnBase();
        $this->asignarAdministradorEnBase($unidadId, $administrador['id'], 15);
        $otroAdministrador = $this->crearAdministradorEnBase();

        $pedidos = [
            fn (ClienteApi $c) => $this->asignar($c, $unidadId, $otroAdministrador['mail']),
            fn (ClienteApi $c) => $c->post('/unidades/administrador/comision', ['unidad_id' => $unidadId, 'porcentaje_comision' => 50]),
            fn (ClienteApi $c) => $c->post('/unidades/administrador/quitar', ['unidad_id' => $unidadId]),
        ];
        $quienes = [
            'el administrador asignado' => [$this->iniciarSesion($administrador['mail']), 403],
            'otro propietario'          => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404],
            'sin sesión'                => [$this->cliente(), 401],
        ];
        foreach ($quienes as $quien => [$cliente, $codigo]) {
            foreach ($pedidos as $i => $pedido) {
                $this->assertSame($codigo, $pedido($cliente)->codigo, "$quien, pedido $i");
            }
        }
        $this->assertSame(['administrador_id' => $administrador['id'], 'porcentaje_comision' => '15.00'], $this->administracion($unidadId));

        foreach (['otro administrador' => $otroAdministrador['mail'], 'un operador' => $this->crearUsuarioEnBase('operador')['mail']] as $quien => $mail) {
            $this->assertSame(404, $this->iniciarSesion($mail)->get('/unidades/gestion', ['id' => $unidadId])->codigo, $quien);
        }
    }

    // ---------- el aviso por mail ----------

    //Al asignarlo, al administrador le llega un mail con la unidad, quién lo asignó y la comisión
    //(necesita Mailpit: en GitHub Actions corre; en una máquina local, si no está levantado, se saltea)
    public function testAlAsignarloLeLlegaUnMail(): void
    {
        $mailpit = new Client(['base_uri' => getenv('MAILPIT_URL') ?: 'http://127.0.0.1:8025', 'timeout' => 5, 'http_errors' => false]);
        try {
            $mailpit->get('/api/v1/info');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Mailpit no está corriendo (en GitHub Actions sí corre este test)');
        }
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $administrador = $this->crearAdministradorEnBase();

        $this->asignar($cliente, $unidadId, $administrador['mail'], 15);

        $busqueda = json_decode((string) $mailpit->get('/api/v1/search', ['query' => ['query' => 'to:"' . $administrador['mail'] . '"']])->getBody(), true);
        $this->assertNotEmpty($busqueda['messages'] ?? [], 'No llegó el aviso a Mailpit');
        $mensaje = json_decode((string) $mailpit->get('/api/v1/message/' . $busqueda['messages'][0]['ID'])->getBody(), true);
        $this->assertSame('Te asignaron la administración de Cabaña del Mar', $mensaje['Subject']);
        $this->assertStringContainsString('Marisa te asignó la administración de Cabaña del Mar, con una comisión del 15 %', $mensaje['Text']);
    }
}
