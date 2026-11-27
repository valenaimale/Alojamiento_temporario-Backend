<?php

namespace App\Controllers;

use App\Request\Request;
use App\Autorizacion\PermisosUnidad;
use App\Validacion\Estadisticas;
use App\Validacion\ValidadorFechas;
use App\Request\Respuesta;

//Las estadísticas de una unidad por temporada (RF-12): ocupación, tarifa promedio, estadía promedio,
//cancelaciones por tramo y huéspedes recurrentes. Las ve el propietario y el administrador asignado a la unidad.
//
//Comparar dos unidades lado a lado (lo que pidió Marisa) es esta misma ruta pedida dos veces, una por unidad:
//no hace falta un endpoint aparte, y así el front elige qué temporada mira en cada una.
class EstadisticasController
{
    //GET /unidades/estadisticas?id=5&temporada_id=3
    //Sin temporada_id devuelve la temporada en curso; si ninguna lo está, la última que ya empezó.
    //Si la unidad no tiene ninguna temporada cargada, temporada y estadisticas vuelven en null: no es el estado
    //vacío de "todavía no hay datos", es que primero hay que cargar una temporada.
    public function ver()
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador(Request::parametroGet('id'));//si no puede, responde y corta acá

        $temporadas = Estadisticas::temporadasDeLaUnidad((int) $unidad['id']);
        $temporada  = $this->temporadaPedida($temporadas);

        Respuesta::json([
            'unidad'       => ['id' => (int) $unidad['id'], 'nombre' => $unidad['nombre']],
            'temporadas'   => $temporadas,
            'temporada'    => $temporada,
            'estadisticas' => $temporada === null ? null : Estadisticas::calcular(
                Estadisticas::deLaUnidad((int) $unidad['id']),
                $temporada['fecha_inicio'],
                $temporada['fecha_fin']
            ),
        ]);
    }

    //La temporada que pidió el front, o la que corresponde mostrar primero. $temporadas viene de la más nueva a la
    //más vieja: la primera que ya empezó es la que está en curso o, si ninguna lo está, la última que terminó.
    //Si todas son futuras (una unidad nueva), la más próxima a hoy, que es la última de la lista.
    private function temporadaPedida(array $temporadas): ?array
    {
        $pedida = Request::enteroDeLaUrl('temporada_id');
        if ($pedida !== null) {
            foreach ($temporadas as $temporada) {
                if ($temporada['id'] === $pedida) {
                    return $temporada;
                }
            }
            //la temporada no existe o es de otra unidad: se responde igual que con una unidad ajena, sin decir cuál es
            Respuesta::json(['error' => 'No encontramos esa temporada'], 404);
            exit;
        }

        $hoy = ValidadorFechas::hoy()->format('Y-m-d');
        foreach ($temporadas as $temporada) {
            if ($temporada['fecha_inicio'] <= $hoy) {
                return $temporada;
            }
        }
        return $temporadas === [] ? null : end($temporadas);
    }
}
