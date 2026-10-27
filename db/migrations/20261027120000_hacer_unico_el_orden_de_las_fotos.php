<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2: dos fotos de la misma unidad no pueden tener el mismo orden (la foto principal es la de orden 0).
//Cambia el índice común (unidad_id, orden) que creó CrearTablaFotosUnidad por uno único: así lo garantiza la base,
//aunque el código tenga un error o lleguen dos pedidos a la vez.
//OJO al correr los orden con un UPDATE: MySQL controla el índice fila por fila, así que hay que decirle en qué orden
//actualizar (ORDER BY orden DESC para sumar 1, ORDER BY orden ASC para restar 1). Ver OrdenFotosTest.
//Usa up()/down() con SQL para cambiar los dos índices en un solo ALTER: la clave foránea de unidad_id necesita
//siempre un índice que empiece por unidad_id, y si se borrara primero el viejo, MySQL lo rechazaría.
final class HacerUnicoElOrdenDeLasFotos extends AbstractMigration
{
    //Reemplaza el índice común por el único (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE fotos_unidad
             ADD UNIQUE KEY uq_fotos_unidad_orden (unidad_id, orden),
             DROP INDEX unidad_id'
        );
    }

    //Vuelve al índice común (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->execute(
            'ALTER TABLE fotos_unidad
             ADD INDEX unidad_id (unidad_id, orden),
             DROP INDEX uq_fotos_unidad_orden'
        );
    }
}
