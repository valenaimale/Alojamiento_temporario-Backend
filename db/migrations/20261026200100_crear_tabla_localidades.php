<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

//Épica 2 y 3: lista cerrada de destinos donde opera la plataforma.
//La búsqueda es por selección de esta lista, no por texto libre (US3.2).
//Las localidades nuevas las da de alta el backoffice; nunca se borran: se marcan activa = 0.
//Usa up()/down() en lugar de change() porque carga datos.
//OJO: en esta versión de Phinx las columnas son NULL por defecto; las obligatorias llevan 'null' => false.
final class CrearTablaLocalidades extends AbstractMigration
{
    //Crea la tabla y carga las localidades iniciales (vendor/bin/phinx migrate)
    public function up(): void
    {
        $this->table('localidades')
            ->addColumn('nombre', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('provincia', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('activa', 'boolean', ['default' => true, 'null' => false])   //0 = deja de ofrecerse en la búsqueda
            ->addIndex(['nombre', 'provincia'], ['unique' => true])
            ->create();

        //carga inicial
        $localidades = [
            'Río Negro'    => ['Las Grutas', 'San Antonio Oeste', 'San Carlos de Bariloche'],
            'Buenos Aires' => ['Villa Gesell', 'Mar de las Pampas', 'Pinamar', 'Mar del Plata', 'Mar de Ajó'],
            'Chubut'       => ['Puerto Madryn'],
            'Córdoba'      => ['Villa Carlos Paz'],
            'Neuquén'      => ['San Martín de los Andes'],
        ];
        $filas = [];
        foreach ($localidades as $provincia => $nombres) {
            foreach ($nombres as $nombre) {
                $filas[] = ['nombre' => $nombre, 'provincia' => $provincia];
            }
        }
        $this->table('localidades')->insert($filas)->saveData();
    }

    //Borra la tabla (vendor/bin/phinx rollback)
    public function down(): void
    {
        $this->table('localidades')->drop()->save();
    }
}
