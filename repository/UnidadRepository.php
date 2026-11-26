<?php

namespace App\Repository;

use App\Database\Conexion;

//Consultas sobre la tabla unidades, compartidas entre varios controllers.
class UnidadRepository
{
    //Traba la fila de la unidad hasta el commit: dos operaciones a la vez sobre la misma unidad no pueden pisarse.
    //Hay que llamarla dentro de una transacción ya abierta ($pdo->beginTransaction()); si no, el lock se libera enseguida.
    public static function lockPorId(int $id): void
    {
        Conexion::obtener()->prepare('SELECT id FROM unidades WHERE id = ? FOR UPDATE')->execute([$id]);
    }
}
