<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use PDO;

//Las fotos de una unidad (US2.1). Las imágenes las sube el navegador directo a Cloudinary: acá solo se guarda su URL y su orden.
//orden 0 = la foto principal (la de la tarjeta). Los orden quedan siempre seguidos: 0, 1, 2...
//La base lo exige con el índice único (unidad_id, orden), así que cada acción:
//  - va en una transacción que empieza trabando la fila de la unidad (SELECT ... FOR UPDATE): si llegan dos pedidos
//    juntos para la misma unidad (el front sube varias fotos a la vez), el segundo espera al primero y recién ahí cuenta las fotos;
//  - corre las fotos con UPDATE ... ORDER BY, porque MySQL controla el índice fila por fila y chocaría en el medio.
//Quitar una foto no la borra de Cloudinary: con el preset sin firma no se puede. Se va a resolver con las subidas firmadas (pendiente).
class FotosUnidadController
{
    //Solo se aceptan imágenes de nuestra cuenta de Cloudinary: si no, cualquiera podría guardar una URL a cualquier sitio
    //y nuestra página la mostraría como foto de la unidad.
    private const PREFIJO_URL = 'https://res.cloudinary.com/ynclnyuh/image/upload/';
    private const MAXIMO_FOTOS = 10;
    //Lugar libre donde se aparta la foto elegida como principal mientras se corren las demás.
    //Como hay como mucho 10 fotos (orden 0 a 9), 999 nunca está ocupado.
    private const ORDEN_APARTADA = 999;

    //POST /unidades/fotos/agregar: el propietario agrega una foto al final. La primera queda como principal.
    public function agregar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');//el administrador recibe 403, otro propietario 404

        $url = is_string($datos['url'] ?? null) ? trim($datos['url']) : '';
        if (!str_starts_with($url, self::PREFIJO_URL) || strlen($url) === strlen(self::PREFIJO_URL)
            || strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $this->error('La foto no es válida');
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->trabarUnidad($pdo, $unidad['id']);

            //se cuentan después de trabar la unidad: así dos subidas juntas no toman el mismo orden
            $consulta = $pdo->prepare('SELECT COUNT(*) FROM fotos_unidad WHERE unidad_id = ?');
            $consulta->execute([$unidad['id']]);
            $cantidad = (int) $consulta->fetchColumn();
            if ($cantidad >= self::MAXIMO_FOTOS) {
                $pdo->rollBack();
                return $this->error('Podés tener hasta ' . self::MAXIMO_FOTOS . ' fotos por unidad');
            }

            $pdo->prepare('INSERT INTO fotos_unidad (unidad_id, url, orden) VALUES (?, ?, ?)')
                ->execute([$unidad['id'], $url, $cantidad]);//va al final; si es la primera, queda con orden 0 (la principal)
            $fotoId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;//lo atrapa el router y responde 500
        }

        http_response_code(201);
        echo json_encode([
            'ok'    => 'Agregamos la foto',
            'foto'  => ['id' => $fotoId, 'url' => $url, 'orden' => $cantidad],
            'fotos' => self::fotosDe((int) $unidad['id']),
        ]);
    }

    //POST /unidades/fotos/quitar: el propietario quita una foto y las que estaban después bajan un lugar.
    //Si quita la principal, la siguiente pasa a ser la principal.
    public function quitar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->trabarUnidad($pdo, $unidad['id']);

            $foto = $this->buscarFoto($pdo, $datos['foto_id'] ?? '', $unidad['id']);
            if ($foto === null) {
                $pdo->rollBack();
                return $this->error('No encontramos esa foto', 404);
            }

            $pdo->prepare('DELETE FROM fotos_unidad WHERE id = ?')->execute([$foto['id']]);
            //hacia abajo, de la más baja a la más alta: cada una va al lugar que acaba de quedar libre
            $pdo->prepare('UPDATE fotos_unidad SET orden = orden - 1 WHERE unidad_id = ? AND orden > ? ORDER BY orden ASC')
                ->execute([$unidad['id'], $foto['orden']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        http_response_code(200);
        echo json_encode(['ok' => 'Quitamos la foto', 'fotos' => self::fotosDe((int) $unidad['id'])]);
    }

    //POST /unidades/fotos/principal: el propietario elige la foto principal (pasa a orden 0).
    //Las que estaban antes que ella suben un lugar; las de después no se mueven.
    public function principal()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->trabarUnidad($pdo, $unidad['id']);

            $foto = $this->buscarFoto($pdo, $datos['foto_id'] ?? '', $unidad['id']);
            if ($foto === null) {
                $pdo->rollBack();
                return $this->error('No encontramos esa foto', 404);
            }

            if ($foto['orden'] > 0) {//si ya es la principal, no hay nada que mover
                //(a) la elegida se aparta a un lugar libre; (b) las de antes suben un lugar, de la más alta a la más baja;
                //(c) la elegida pasa a ser la 0
                $pdo->prepare('UPDATE fotos_unidad SET orden = ? WHERE id = ?')->execute([self::ORDEN_APARTADA, $foto['id']]);
                $pdo->prepare('UPDATE fotos_unidad SET orden = orden + 1 WHERE unidad_id = ? AND orden < ? ORDER BY orden DESC')
                    ->execute([$unidad['id'], $foto['orden']]);
                $pdo->prepare('UPDATE fotos_unidad SET orden = 0 WHERE id = ?')->execute([$foto['id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        http_response_code(200);
        echo json_encode(['ok' => 'Elegiste la foto principal', 'fotos' => self::fotosDe((int) $unidad['id'])]);
    }

    //POST /unidades/fotos/mover: inserta una foto en la posición elegida (desde 1).
    //La posición 1 es la portada. El resto conserva su orden relativo.
    public function mover()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');
        $valor = $datos['posicion'] ?? null;
        $posicion = is_bool($valor) ? false : filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($posicion === false) {
            return $this->error('Elegí una posición válida para la foto', 422);
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->trabarUnidad($pdo, $unidad['id']);
            $foto = $this->buscarFoto($pdo, $datos['foto_id'] ?? '', $unidad['id']);
            if ($foto === null) {
                $pdo->rollBack();
                return $this->error('No encontramos esa foto', 404);
            }
            $cantidad = $pdo->prepare('SELECT COUNT(*) FROM fotos_unidad WHERE unidad_id = ?');
            $cantidad->execute([$unidad['id']]);
            if ($posicion > (int) $cantidad->fetchColumn()) {
                $pdo->rollBack();
                return $this->error('Elegí una posición válida para la foto', 422);
            }
            $destino = $posicion - 1;
            if ($destino !== $foto['orden']) {
                // Apartar deja un hueco. Desplazar en este orden evita colisiones del índice único.
                $actualizar = $pdo->prepare('UPDATE fotos_unidad SET orden = ? WHERE id = ?');
                $actualizar->execute([self::ORDEN_APARTADA, $foto['id']]);
                if ($destino < $foto['orden']) {
                    $pdo->prepare('UPDATE fotos_unidad SET orden = orden + 1 WHERE unidad_id = ? AND orden >= ? AND orden < ? ORDER BY orden DESC')
                        ->execute([$unidad['id'], $destino, $foto['orden']]);
                } else {
                    $pdo->prepare('UPDATE fotos_unidad SET orden = orden - 1 WHERE unidad_id = ? AND orden > ? AND orden <= ? ORDER BY orden ASC')
                        ->execute([$unidad['id'], $foto['orden'], $destino]);
                }
                $actualizar->execute([$destino, $foto['id']]);
            }
            $fotos = self::fotosDe((int) $unidad['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        http_response_code(200);
        echo json_encode(['ok' => 'Guardamos el orden de las fotos', 'fotos' => $fotos]);
    }

    //Las fotos de la unidad ordenadas (la principal primero), como las devuelve la API: [{id, url, orden}].
    //La usan GET /unidades/datos y las rutas de fotos (que devuelven la lista actualizada).
    public static function fotosDe(int $unidadId): array
    {
        $consulta = Conexion::obtener()->prepare('SELECT id, url, orden FROM fotos_unidad WHERE unidad_id = ? ORDER BY orden');
        $consulta->execute([$unidadId]);

        $fotos = [];
        foreach ($consulta->fetchAll() as $foto) {
            $fotos[] = ['id' => (int) $foto['id'], 'url' => $foto['url'], 'orden' => (int) $foto['orden']];
        }
        return $fotos;
    }

    //Traba la fila de la unidad hasta el commit: los pedidos de fotos de esa unidad se atienden de a uno
    private function trabarUnidad(PDO $pdo, int|string $unidadId): void
    {
        $pdo->prepare('SELECT id FROM unidades WHERE id = ? FOR UPDATE')->execute([$unidadId]);
    }

    //La foto [id, orden] si es de esa unidad; si no existe, es de otra unidad o el id no es válido, null
    private function buscarFoto(PDO $pdo, mixed $fotoId, int|string $unidadId): ?array
    {
        $id = is_bool($fotoId) ? false : filter_var($fotoId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        $consulta = $pdo->prepare('SELECT id, orden FROM fotos_unidad WHERE id = ? AND unidad_id = ?');
        $consulta->execute([$id, $unidadId]);
        $foto = $consulta->fetch();
        return $foto === false ? null : ['id' => (int) $foto['id'], 'orden' => (int) $foto['orden']];
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
