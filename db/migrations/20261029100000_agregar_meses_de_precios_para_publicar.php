<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 3: para publicar una unidad, todas sus noches desde mañana hasta dentro de N meses tienen que tener precio
//o estar bloqueadas (decidido el 29/10; reemplaza a "al menos una noche con precio"). N es un parámetro,
//así el backoffice lo puede cambiar sin tocar el código. Lo usa ReglasPublicacion.
//Usa up()/down() porque carga datos.
final class AgregarMesesDePreciosParaPublicar extends AbstractMigration
{
    //Agrega el parámetro con 6 meses (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('parametros')->insert([
            'clave'       => 'meses_precios_para_publicar',
            'valor'       => '6',
            'descripcion' => 'Meses hacia adelante que tienen que tener precio (o estar bloqueados) para publicar una unidad',
        ])->saveData();
    }

    //Quita el parámetro (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->execute("DELETE FROM parametros WHERE clave = 'meses_precios_para_publicar'");
    }
}
