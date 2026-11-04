<?php

namespace Tests\Integracion;

use Tests\Soporte\ClienteApi;

//Las fotos de una unidad: POST /unidades/fotos/agregar, /unidades/fotos/quitar y /unidades/fotos/principal.
//Revisa sobre todo que los orden queden siempre seguidos (0, 1, 2...) y que la principal sea la 0.
final class FotosUnidadTest extends CasoDeIntegracion
{
    private const PREFIJO = 'https://res.cloudinary.com/ynclnyuh/image/upload/';

    //Una URL de nuestra cuenta de Cloudinary, distinta para cada foto
    private function url(string $nombre): string
    {
        return self::PREFIJO . "v1/unidades/$nombre.jpg";
    }

    //Un propietario con su sesión iniciada y una unidad sin fotos. Devuelve [el cliente, el id de la unidad].
    private function propietarioConUnidad(): array
    {
        $propietario = $this->crearPropietarioEnBase();
        return [$this->iniciarSesion($propietario['mail']), $this->crearUnidadEnBase($propietario['id'])];
    }

    //Agrega las fotos por la API, en orden. Devuelve los ids, en el mismo orden.
    private function agregarFotos(ClienteApi $cliente, int $unidadId, array $nombres): array
    {
        $ids = [];
        foreach ($nombres as $nombre) {
            $respuesta = $cliente->post('/unidades/fotos/agregar', ['unidad_id' => $unidadId, 'url' => $this->url($nombre)]);
            $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
            $ids[] = $respuesta->json()['foto']['id'];
        }
        return $ids;
    }

    //Las fotos de la unidad en la base como [orden => nombre], para ver de un vistazo cómo quedaron
    private function ordenEnBase(int $unidadId): array
    {
        $consulta = $this->pdo()->prepare('SELECT orden, url FROM fotos_unidad WHERE unidad_id = ? ORDER BY orden');
        $consulta->execute([$unidadId]);
        $fotos = [];
        foreach ($consulta->fetchAll() as $foto) {
            $fotos[(int) $foto['orden']] = basename($foto['url'], '.jpg');
        }
        return $fotos;
    }

    // ---------- Agregar ----------

    //La primera foto queda como principal (orden 0) y la respuesta trae la foto y la lista
    public function testLaPrimeraFotoQuedaComoPrincipal(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $respuesta = $cliente->post('/unidades/fotos/agregar', ['unidad_id' => $unidadId, 'url' => $this->url('frente')]);

        $this->assertSame(201, $respuesta->codigo, $respuesta->cuerpo);
        $json = $respuesta->json();
        $this->assertSame('Agregamos la foto', $json['ok']);
        $this->assertSame(['id' => $json['foto']['id'], 'url' => $this->url('frente'), 'orden' => 0], $json['foto']);
        $this->assertSame([$json['foto']], $json['fotos']);
    }

    //Varias fotos seguidas van al final: los orden quedan 0, 1, 2... sin repetirse
    public function testVariasFotosSeguidasQuedanEnOrden(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();

        $this->agregarFotos($cliente, $unidadId, ['a', 'b', 'c', 'd']);

        $this->assertSame(['a', 'b', 'c', 'd'], $this->ordenEnBase($unidadId));
    }

    //Como mucho 10 fotos: la 11.ª responde 422 y no se guarda
    public function testComoMuchoDiezFotos(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $this->agregarFotos($cliente, $unidadId, range(0, 9));

        $respuesta = $cliente->post('/unidades/fotos/agregar', ['unidad_id' => $unidadId, 'url' => $this->url('once')]);

        $this->assertSame(422, $respuesta->codigo);
        $this->assertSame('Podés tener hasta 10 fotos por unidad', $respuesta->json()['error']);
        $this->assertCount(10, $this->ordenEnBase($unidadId));
    }

    //Solo URLs de nuestra cuenta de Cloudinary, de hasta 500 caracteres
    public function testSoloAceptaFotosDeNuestraCuentaDeCloudinary(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $invalidas = [
            'otro dominio'          => 'https://ejemplo.com/foto.jpg',
            'otra cuenta'           => 'https://res.cloudinary.com/otra-cuenta/image/upload/foto.jpg',
            'parecida'              => 'https://res.cloudinary.com/ynclnyuh/image/upload.ejemplo.com/foto.jpg',
            'sin https'             => 'http://res.cloudinary.com/ynclnyuh/image/upload/foto.jpg',
            'solo el prefijo'       => self::PREFIJO,
            'más de 500 caracteres' => self::PREFIJO . str_repeat('a', 501 - strlen(self::PREFIJO)),
            'vacía'                 => '',
            'un número'             => 5,
        ];

        foreach ($invalidas as $caso => $url) {
            $respuesta = $cliente->post('/unidades/fotos/agregar', ['unidad_id' => $unidadId, 'url' => $url]);
            $this->assertSame(422, $respuesta->codigo, $caso);
            $this->assertSame('La foto no es válida', $respuesta->json()['error'], $caso);
        }
        $this->assertSame([], $this->ordenEnBase($unidadId));

        $justa = self::PREFIJO . str_repeat('a', 500 - strlen(self::PREFIJO));
        $this->assertSame(201, $cliente->post('/unidades/fotos/agregar', ['unidad_id' => $unidadId, 'url' => $justa])->codigo);
    }

    // ---------- Quitar ----------

    //Quitar la principal: la siguiente pasa a ser la 0 y no quedan huecos
    public function testQuitarLaPrincipal(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [$a] = $this->agregarFotos($cliente, $unidadId, ['a', 'b', 'c']);

        $respuesta = $cliente->post('/unidades/fotos/quitar', ['unidad_id' => $unidadId, 'foto_id' => $a]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Quitamos la foto', $respuesta->json()['ok']);
        $this->assertSame([0, 1], array_column($respuesta->json()['fotos'], 'orden'));
        $this->assertSame(['b', 'c'], $this->ordenEnBase($unidadId));
    }

    //Quitar una del medio: las de después bajan un lugar
    public function testQuitarUnaDelMedio(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [, $b] = $this->agregarFotos($cliente, $unidadId, ['a', 'b', 'c', 'd']);

        $respuesta = $cliente->post('/unidades/fotos/quitar', ['unidad_id' => $unidadId, 'foto_id' => $b]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['a', 'c', 'd'], $this->ordenEnBase($unidadId));
    }

    //Quitar la última foto deja la unidad sin fotos
    public function testQuitarLaUnicaFoto(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [$a] = $this->agregarFotos($cliente, $unidadId, ['a']);

        $respuesta = $cliente->post('/unidades/fotos/quitar', ['unidad_id' => $unidadId, 'foto_id' => $a]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([], $respuesta->json()['fotos']);
    }

    // ---------- Principal ----------

    //Elegir la principal: la elegida pasa a 0, las de antes suben un lugar y las de después no se mueven
    public function testElegirLaPrincipal(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [, , $c] = $this->agregarFotos($cliente, $unidadId, ['a', 'b', 'c', 'd']);

        $respuesta = $cliente->post('/unidades/fotos/principal', ['unidad_id' => $unidadId, 'foto_id' => $c]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame('Elegiste la foto principal', $respuesta->json()['ok']);
        $this->assertSame([0, 1, 2, 3], array_column($respuesta->json()['fotos'], 'orden'));
        $this->assertSame($c, $respuesta->json()['fotos'][0]['id']);
        $this->assertSame(['c', 'a', 'b', 'd'], $this->ordenEnBase($unidadId));
    }

    //Elegir la última de 10 (la que más se mueve)
    public function testElegirLaUltimaDeDiez(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $ids = $this->agregarFotos($cliente, $unidadId, range(0, 9));

        $respuesta = $cliente->post('/unidades/fotos/principal', ['unidad_id' => $unidadId, 'foto_id' => $ids[9]]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['9', '0', '1', '2', '3', '4', '5', '6', '7', '8'], array_map('strval', $this->ordenEnBase($unidadId)));
    }

    //Elegir la que ya es principal no cambia nada
    public function testElegirLaQueYaEsPrincipal(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [$a] = $this->agregarFotos($cliente, $unidadId, ['a', 'b', 'c']);

        $respuesta = $cliente->post('/unidades/fotos/principal', ['unidad_id' => $unidadId, 'foto_id' => $a]);

        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['a', 'b', 'c'], $this->ordenEnBase($unidadId));
    }

    // ---------- Permisos ----------

    public function testMoverFotosInsertaEnLaPosicionYPersisteLaPortada(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [$a, $b, $c, $d] = $this->agregarFotos($cliente, $unidadId, ['a', 'b', 'c', 'd']);
        $respuesta = $cliente->post('/unidades/fotos/mover', ['unidad_id' => $unidadId, 'foto_id' => $c, 'posicion' => 2]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame([$a, $c, $b, $d], array_column($respuesta->json()['fotos'], 'id'));
        $this->assertSame([0, 1, 2, 3], array_column($respuesta->json()['fotos'], 'orden'));
        $this->assertSame(['a', 'c', 'b', 'd'], $this->ordenEnBase($unidadId));

        $respuesta = $cliente->post('/unidades/fotos/mover', ['unidad_id' => $unidadId, 'foto_id' => $a, 'posicion' => 2]);
        $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
        $this->assertSame(['c', 'a', 'b', 'd'], $this->ordenEnBase($unidadId));
        $fotos = $cliente->get('/unidades/datos', ['id' => $unidadId])->json()['unidad']['fotos'];
        $this->assertSame($c, $fotos[0]['id']);
        $this->pdo()->prepare("UPDATE unidades SET estado_publicacion = 'publicada' WHERE id = ?")->execute([$unidadId]);
        $publico = $this->cliente()->get('/alojamiento', ['id' => $unidadId]);
        $this->assertSame([$this->url('c'), $this->url('a'), $this->url('b'), $this->url('d')], $publico->json()['alojamiento']['fotos']);
    }

    public function testMoverFotosValidaPosicionYNoModificaSiYaEstaEnSuLugar(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [$a, $b] = $this->agregarFotos($cliente, $unidadId, ['a', 'b']);
        foreach ([[$a, 1], [$b, 2]] as [$id, $posicion]) {
            $respuesta = $cliente->post('/unidades/fotos/mover', ['unidad_id' => $unidadId, 'foto_id' => $id, 'posicion' => $posicion]);
            $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
            $this->assertSame([$a, $b], array_column($respuesta->json()['fotos'], 'id'));
        }
        foreach (['', 'segunda', 0, -1, 3, 11, 1.5, true, null, [1]] as $posicion) {
            $respuesta = $cliente->post('/unidades/fotos/mover', ['unidad_id' => $unidadId, 'foto_id' => $a, 'posicion' => $posicion]);
            $this->assertSame(422, $respuesta->codigo, $respuesta->cuerpo);
        }
        $this->assertSame(['a', 'b'], $this->ordenEnBase($unidadId));
    }

    public function testMoverDirectamenteEntreTodasLasPosicionesConservaLasDemasFotos(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        $ids = $this->agregarFotos($cliente, $unidadId, ['a', 'b', 'c', 'd', 'e']);
        $esperado = $ids;
        foreach ($ids as $id) {
            foreach ([5, 1, 4, 2, 3] as $posicion) {
                $origen = array_search($id, $esperado, true);
                array_splice($esperado, $origen, 1);
                array_splice($esperado, $posicion - 1, 0, [$id]);
                $respuesta = $cliente->post('/unidades/fotos/mover', ['unidad_id' => $unidadId, 'foto_id' => $id, 'posicion' => $posicion]);
                $this->assertSame(200, $respuesta->codigo, $respuesta->cuerpo);
                $this->assertSame($esperado, array_column($respuesta->json()['fotos'], 'id'));
                $this->assertSame([0, 1, 2, 3, 4], array_column($respuesta->json()['fotos'], 'orden'));
            }
        }
        $fotos = $cliente->get('/unidades/datos', ['id' => $unidadId])->json()['unidad']['fotos'];
        $this->assertSame($esperado, array_column($fotos, 'id'));
    }

    //Una foto de otra unidad (aunque sea del mismo propietario) o un foto_id inválido: 404 y no cambia nada
    public function testUnaFotoDeOtraUnidad(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $cliente = $this->iniciarSesion($propietario['mail']);
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $otraUnidad = $this->crearUnidadEnBase($propietario['id']);
        $this->agregarFotos($cliente, $unidadId, ['a', 'b']);
        [$deLaOtra] = $this->agregarFotos($cliente, $otraUnidad, ['x', 'y']);

        foreach (['/unidades/fotos/quitar', '/unidades/fotos/principal', '/unidades/fotos/mover'] as $ruta) {
            foreach ([$deLaOtra, 999999, 'abc', null] as $fotoId) {
                $respuesta = $cliente->post($ruta, ['unidad_id' => $unidadId, 'foto_id' => $fotoId, 'posicion' => 1]);
                $this->assertSame(404, $respuesta->codigo, "$ruta con foto_id " . var_export($fotoId, true));
                $this->assertSame('No encontramos esa foto', $respuesta->json()['error']);
            }
        }
        $this->assertSame(['a', 'b'], $this->ordenEnBase($unidadId));
        $this->assertSame(['x', 'y'], $this->ordenEnBase($otraUnidad));
    }

    //Las fotos son solo del propietario: el administrador asignado recibe 403 (no es propietario)
    //y otro propietario 404 (la unidad no es suya). Sin sesión, 401.
    public function testSoloElPropietarioManejaLasFotos(): void
    {
        $propietario = $this->crearPropietarioEnBase();
        $administrador = $this->crearAdministradorEnBase();
        $unidadId = $this->crearUnidadEnBase($propietario['id']);
        $this->asignarAdministradorEnBase($unidadId, $administrador['id']);
        [$a] = $this->agregarFotos($this->iniciarSesion($propietario['mail']), $unidadId, ['a', 'b']);
        $pedidos = [
            '/unidades/fotos/agregar'   => ['unidad_id' => $unidadId, 'url' => $this->url('ajena')],
            '/unidades/fotos/quitar'    => ['unidad_id' => $unidadId, 'foto_id' => $a],
            '/unidades/fotos/principal' => ['unidad_id' => $unidadId, 'foto_id' => $a],
            '/unidades/fotos/mover'     => ['unidad_id' => $unidadId, 'foto_id' => $a, 'posicion' => 2],
        ];
        $quienes = [
            'el administrador' => [$this->iniciarSesion($administrador['mail']), 403],
            'otro propietario' => [$this->iniciarSesion($this->crearPropietarioEnBase()['mail']), 404],
            'sin sesión'       => [$this->cliente(), 401],
        ];

        foreach ($quienes as $quien => [$cliente, $codigo]) {
            foreach ($pedidos as $ruta => $cuerpo) {
                $this->assertSame($codigo, $cliente->post($ruta, $cuerpo)->codigo, "$quien en $ruta");
            }
        }
        $this->assertSame(['a', 'b'], $this->ordenEnBase($unidadId));
    }

    //GET /unidades/datos trae las fotos en su orden nuevo, con la principal primero
    public function testLosDatosDeLaUnidadTraenLasFotosOrdenadas(): void
    {
        [$cliente, $unidadId] = $this->propietarioConUnidad();
        [, $b] = $this->agregarFotos($cliente, $unidadId, ['a', 'b']);
        $cliente->post('/unidades/fotos/principal', ['unidad_id' => $unidadId, 'foto_id' => $b]);

        $fotos = $cliente->get('/unidades/datos', ['id' => $unidadId])->json()['unidad']['fotos'];

        $this->assertSame([$this->url('b'), $this->url('a')], array_column($fotos, 'url'));
        $this->assertSame([0, 1], array_column($fotos, 'orden'));
    }
}
