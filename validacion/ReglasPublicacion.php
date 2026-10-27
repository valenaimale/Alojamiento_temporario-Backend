<?php

namespace App\Validacion;

use App\Database\Conexion;

//Qué le falta a una unidad para poder publicarse (US3.1).
//La usan "Mis unidades" (para mostrar "sin publicar y por qué"), la página central de cada unidad
//y la acción de publicar: así la regla está escrita una sola vez.
class ReglasPublicacion
{
    //Devuelve la lista de lo que falta, con un mensaje para el propietario por cada cosa.
    //Vacía = se puede publicar.
    public static function faltantes(int $unidadId): array
    {
        $faltantes = [];

        //RN-01: al menos una noche con precio vigente, de hoy en adelante (la temporada sola no alcanza:
        //el precio está en precio_noche, y una noche sin precio no se puede reservar)
        $consulta = Conexion::obtener()->prepare(
            'SELECT 1 FROM precio_noche
             WHERE unidad_id = ? AND fecha >= ? AND vigente_hasta IS NULL
             LIMIT 1'
        );
        $consulta->execute([$unidadId, ValidadorFechas::hoy()->format('Y-m-d')]);//la fecha de hoy de Argentina, no la de MySQL
        if (!$consulta->fetchColumn()) {
            $faltantes[] = 'Cargá el precio de al menos una noche, de hoy en adelante';
        }

        return $faltantes;
    }
}
