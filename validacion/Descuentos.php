<?php

namespace App\Validacion;

//Descuentos por estadía larga de cada unidad (US2.10, decisión #69 de docs/pagos-decisiones.md).
//Cada unidad tiene escalones en descuentos_estadia (por ejemplo, 10 % desde 10 noches y 15 % desde 14), y a una
//estadía se le aplica el escalón más alto que alcanza. Lo usa Estadia::calcular: así la búsqueda, el detalle, la
//reserva y la extensión cobran lo mismo.
//FASE 0: por ahora devuelve siempre 0 %. Lo completa la rama `descuentos`.
class Descuentos
{
    //El % de descuento ("10.00") que le toca a una estadía de $noches noches en la unidad; "0.00" si no alcanza ninguno
    public static function porcentaje(int $unidadId, int $noches): string
    {
        return '0.00';
    }
}
