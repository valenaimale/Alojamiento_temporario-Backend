<?php

namespace Tests\Integracion;

use PDOException;

//El índice único de fotos_unidad (unidad_id, orden): la base no deja dos fotos de la misma unidad con el mismo orden.
//Prueba la base directo (sin pasar por la API), igual que se prueban las reglas que garantiza MySQL.
//También muestra cómo hay que correr los orden con un UPDATE para que el índice no salte en el medio.
final class OrdenFotosTest extends CasoDeIntegracion
{
    private const URL = 'https://res.cloudinary.com/ynclnyuh/image/upload/unidades/prueba.jpg';

    //Una unidad con 3 fotos, de orden 0, 1 y 2. Devuelve su id.
    private function unidadConTresFotos(): int
    {
        $unidadId = $this->crearUnidadEnBase($this->crearPropietarioEnBase()['id']);
        foreach ([0, 1, 2] as $orden) {
            $this->agregarFoto($unidadId, $orden);
        }
        return $unidadId;
    }

    //Inserta una foto con ese orden
    private function agregarFoto(int $unidadId, int $orden): void
    {
        $this->pdo()->prepare('INSERT INTO fotos_unidad (unidad_id, url, orden) VALUES (?, ?, ?)')
            ->execute([$unidadId, self::URL, $orden]);
    }

    //Los orden de las fotos de la unidad, de menor a mayor
    private function ordenes(int $unidadId): array
    {
        $consulta = $this->pdo()->prepare('SELECT orden FROM fotos_unidad WHERE unidad_id = ? ORDER BY orden');
        $consulta->execute([$unidadId]);
        return array_map('intval', $consulta->fetchAll(\PDO::FETCH_COLUMN));
    }

    //Dos fotos de la misma unidad con orden 0 (dos principales): la base la rechaza
    public function testLaBaseNoDejaDosFotosConElMismoOrden(): void
    {
        $unidadId = $this->unidadConTresFotos();

        try {
            $this->agregarFoto($unidadId, 0);
            $this->fail('La base aceptó dos fotos con orden 0 en la misma unidad');
        } catch (PDOException $e) {
            $this->assertSame('23000', $e->getCode(), $e->getMessage());//23000: se violó una restricción de integridad
        }
        $this->assertSame([0, 1, 2], $this->ordenes($unidadId));
    }

    //Cada unidad tiene su propia foto principal: el mismo orden en unidades distintas no choca
    public function testElMismoOrdenEnOtraUnidadSePuede(): void
    {
        $this->unidadConTresFotos();
        $otraUnidad = $this->unidadConTresFotos();

        $this->assertSame([0, 1, 2], $this->ordenes($otraUnidad));
    }

    //Correr las fotos un lugar hacia arriba: con ORDER BY orden DESC funciona; sin él, choca en el medio
    public function testCorrerLasFotosHaciaArribaNecesitaOrderByDesc(): void
    {
        $unidadId = $this->unidadConTresFotos();

        //sin ORDER BY, MySQL puede pasar primero la 0 a 1 cuando la 1 todavía existe
        try {
            $this->pdo()->prepare('UPDATE fotos_unidad SET orden = orden + 1 WHERE unidad_id = ? ORDER BY orden ASC')
                ->execute([$unidadId]);
            $this->fail('El UPDATE en el orden equivocado no chocó con el índice único');
        } catch (PDOException $e) {
            $this->assertSame('23000', $e->getCode(), $e->getMessage());
        }

        //de la más alta a la más baja, cada una va a un lugar libre
        $this->pdo()->prepare('UPDATE fotos_unidad SET orden = orden + 1 WHERE unidad_id = ? ORDER BY orden DESC')
            ->execute([$unidadId]);
        $this->assertSame([1, 2, 3], $this->ordenes($unidadId));
    }

    //Al quitar la principal, las demás bajan un lugar con ORDER BY orden ASC y la siguiente pasa a ser la principal
    public function testQuitarLaPrincipalYCorrerLasDemasHaciaAbajo(): void
    {
        $unidadId = $this->unidadConTresFotos();

        $this->pdo()->prepare('DELETE FROM fotos_unidad WHERE unidad_id = ? AND orden = 0')->execute([$unidadId]);
        $this->pdo()->prepare('UPDATE fotos_unidad SET orden = orden - 1 WHERE unidad_id = ? AND orden > 0 ORDER BY orden ASC')
            ->execute([$unidadId]);

        $this->assertSame([0, 1], $this->ordenes($unidadId));
    }
}
